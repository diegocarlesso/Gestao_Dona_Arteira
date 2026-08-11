<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\SetProductPriceService;
use App\Modules\Integrations\WooCommerce\Jobs\PushCancellationToWoo;
use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Integrations\WooCommerce\Models\WooWebhookEvent;
use App\Modules\Integrations\WooCommerce\Services\ImportWooOrder;
use App\Modules\Integrations\WooCommerce\Services\ResultadoDaImportacao;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\RecordMovementService;
use App\Modules\Sales\Enums\CustomerOrigin;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\FulfillmentService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Entrada de pedidos do WooCommerce — corte 4 (docs/16 §4, ADR-0007)
|--------------------------------------------------------------------------
|
| Só a entrada (Woo→ERP): o pedido do site vira pedido no ERP, casado por
| id do Woo, confirmado com reserva quando dá, rascunho+pendência quando
| não. A saída (status/rastreio de volta) é o corte 3, em standby.
|
| Dois gatilhos (webhook e puxada), um miolo (ImportWooOrder): os três
| grupos abaixo exercitam o mesmo núcleo por caminhos diferentes.
|
*/

beforeEach(function () {
    Location::factory()->padrao()->create();
    config()->set('integrations.woocommerce.webhook_secret', 'segredo_teste');
});

/** Um produto do ERP com preço, estoque opcional e mapeamento id-do-Woo. */
function produtoWooMapeado(int $wooId, string $preco = '100.00', string $estoque = '0'): Product
{
    $produto = Product::factory()->create();
    app(SetProductPriceService::class)->handle($produto, PriceList::Retail, $preco);

    if (bccomp($estoque, '0', 3) === 1) {
        $local = Location::query()->where('is_default', true)->firstOrFail();
        app(RecordMovementService::class)->handle($produto->id, $local->id, MovementType::ProductionOutput, $estoque);
    }

    IntegrationMapping::registrar('product', $produto->id, $wooId);

    return $produto;
}

/**
 * @param  list<array<string, mixed>>  $lineItems
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function pedidoWoo(int $id, array $lineItems, array $extra = []): array
{
    return array_merge([
        'id' => $id,
        'number' => (string) $id,
        'status' => 'processing',
        'customer_id' => 0,
        'total' => '0.00',
        'shipping_total' => '0.00',
        'billing' => [
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'email' => 'ana@example.com',
            'phone' => '4899990000',
        ],
        'line_items' => $lineItems,
    ], $extra);
}

/**
 * Uma linha de pedido do Woo. `$wooProductId` é o id no site (chave do
 * mapeamento), não o id local.
 *
 * @return array<string, mixed>
 */
function itemWoo(int $wooProductId, int $qty, string $price, int $variationId = 0): array
{
    return [
        'id' => fake()->unique()->numberBetween(1, 99999),
        'name' => "Produto {$wooProductId}",
        'product_id' => $wooProductId,
        'variation_id' => $variationId,
        'quantity' => $qty,
        'price' => $price,
        'subtotal' => bcmul((string) $qty, $price, 2),
        'total' => bcmul((string) $qty, $price, 2),
        'sku' => '',
    ];
}

/**
 * Posta no endpoint do webhook com a assinatura HMAC do corpo cru.
 * `$secret = null` simula o ping de ativação (sem assinatura).
 *
 * @param  array<string, mixed>  $payload
 */
function postWebhook(array $payload, ?string $secret = 'segredo_teste', string $topic = 'order.created'): TestResponse
{
    $corpo = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '';

    // Server vars explícitos: a assinatura é conferida sobre o corpo cru, e
    // passar o header por aqui garante que ele chega ao request byte a byte
    // como foi assinado — sem depender do merge do `withHeaders`.
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WC_WEBHOOK_TOPIC' => $topic,
    ];

    if ($secret !== null) {
        $server['HTTP_X_WC_WEBHOOK_SIGNATURE'] = base64_encode(hash_hmac('sha256', $corpo, $secret, true));
    }

    return test()->call('POST', '/webhooks/woocommerce/orders', [], [], [], $server, $corpo);
}

// ============================================================ o miolo

describe('importação Woo→ERP', function () {
    it('importa pedido casado e com saldo como confirmado e reservado', function () {
        produtoWooMapeado(501, '100.00', '5');

        $r = app(ImportWooOrder::class)->handle(
            pedidoWoo(1001, [itemWoo(501, 2, '100.00')], ['total' => '200.00'])
        );

        expect($r->status)->toBe(ResultadoDaImportacao::IMPORTADO);

        $pedido = Order::query()->find($r->orderId);
        expect($pedido)->not->toBeNull()
            ->and($pedido->status)->toBe(OrderStatus::Confirmed)
            ->and($pedido->channel)->toBe(OrderChannel::WooCommerce)
            ->and($pedido->channel_order_ref)->toBe('1001')
            ->and($pedido->total)->toBe('200.00');

        // A reserva do pedido está ativa (BR-203).
        expect(StockReservation::query()
            ->where('reference_type', 'order')
            ->where('reference_id', $pedido->id)
            ->where('status', ReservationStatus::Active->value)
            ->exists())->toBeTrue();

        // E o pedido está mapeado ao id do Woo (idempotência, BR-704).
        expect(IntegrationMapping::localDe('order', 1001))->toBe($pedido->id);
    });

    it('deixa em rascunho, com pendência, quando um item não casa', function () {
        produtoWooMapeado(501, '100.00', '5');

        // O item 999 não tem mapeamento — nunca casa.
        $r = app(ImportWooOrder::class)->handle(
            pedidoWoo(1002, [itemWoo(501, 1, '100.00'), itemWoo(999, 1, '50.00')], ['total' => '150.00'])
        );

        expect($r->status)->toBe(ResultadoDaImportacao::PENDENTE)
            ->and($r->naoMapeados)->toHaveCount(1);

        // Só o item que casou virou linha; o pedido existe (venda não se
        // perde) mas não confirma nem reserva.
        $pedido = Order::query()->find($r->orderId);
        expect($pedido->status)->toBe(OrderStatus::Draft)
            ->and($pedido->items()->count())->toBe(1)
            ->and($pedido->notes)->toContain('sem produto no catálogo');

        expect(StockReservation::query()->count())->toBe(0);
    });

    it('entra em rascunho quando não há disponível para reservar', function () {
        produtoWooMapeado(501, '100.00', '0'); // sem estoque

        $r = app(ImportWooOrder::class)->handle(
            pedidoWoo(1004, [itemWoo(501, 1, '100.00')], ['total' => '100.00'])
        );

        $pedido = Order::query()->find($r->orderId);
        expect($r->status)->toBe(ResultadoDaImportacao::PENDENTE)
            ->and($pedido->status)->toBe(OrderStatus::Draft)
            ->and($pedido->notes)->toContain('Sem disponível');

        expect(StockReservation::query()->count())->toBe(0);
    });

    it('não recria o pedido já importado (idempotência, BR-703)', function () {
        produtoWooMapeado(501, '100.00', '5');
        $order = pedidoWoo(1003, [itemWoo(501, 1, '100.00')], ['total' => '100.00']);

        $import = app(ImportWooOrder::class);
        $import->handle($order);
        $segundo = $import->handle($order);

        expect($segundo->status)->toBe(ResultadoDaImportacao::DUPLICADO)
            ->and(Order::query()->where('channel_order_ref', '1003')->count())->toBe(1);
    });

    it('ignora status não importável, sem criar pedido', function () {
        produtoWooMapeado(501, '100.00', '5');

        $r = app(ImportWooOrder::class)->handle(
            pedidoWoo(1008, [itemWoo(501, 1, '100.00')], ['status' => 'pending', 'total' => '100.00'])
        );

        expect($r->status)->toBe(ResultadoDaImportacao::IGNORADO)
            ->and(Order::query()->count())->toBe(0);
    });

    it('importa completed sem reservar — a peça já saiu do site', function () {
        produtoWooMapeado(501, '100.00', '5');

        $r = app(ImportWooOrder::class)->handle(
            pedidoWoo(1009, [itemWoo(501, 1, '100.00')], ['status' => 'completed', 'total' => '100.00'])
        );

        $pedido = Order::query()->find($r->orderId);
        expect($r->status)->toBe(ResultadoDaImportacao::PENDENTE)
            ->and($pedido->status)->toBe(OrderStatus::Draft);

        expect(StockReservation::query()->count())->toBe(0);
    });

    it('deduplica o cliente pelo e-mail entre pedidos, ignorando caixa', function () {
        produtoWooMapeado(501, '100.00', '10');
        $import = app(ImportWooOrder::class);

        $import->handle(pedidoWoo(1005, [itemWoo(501, 1, '100.00')], [
            'total' => '100.00',
            'billing' => ['first_name' => 'Ana', 'last_name' => 'Silva', 'email' => 'ana@example.com'],
        ]));

        $import->handle(pedidoWoo(1006, [itemWoo(501, 1, '100.00')], [
            'total' => '100.00',
            'billing' => ['first_name' => 'Ana', 'last_name' => 'Silva', 'email' => 'ANA@EXAMPLE.COM'],
        ]));

        expect(Customer::query()->where('origin', CustomerOrigin::Woo->value)->count())->toBe(1);
    });

    it('cria o cliente do convidado a partir do e-mail de cobrança', function () {
        produtoWooMapeado(501, '100.00', '10');

        app(ImportWooOrder::class)->handle(
            pedidoWoo(1007, [itemWoo(501, 1, '100.00')], ['total' => '100.00', 'customer_id' => 0])
        );

        $cliente = Customer::query()->where('email', 'ana@example.com')->first();

        expect($cliente)->not->toBeNull()
            ->and($cliente->origin)->toBe(CustomerOrigin::Woo);
    });

    it('extrai o CPF do meta_data e infere pessoa física', function () {
        produtoWooMapeado(501, '100.00', '10');

        app(ImportWooOrder::class)->handle(
            pedidoWoo(1010, [itemWoo(501, 1, '100.00')], [
                'total' => '100.00',
                'meta_data' => [['key' => '_billing_cpf', 'value' => '390.533.447-05']],
            ])
        );

        $cliente = Customer::query()->where('email', 'ana@example.com')->first();

        expect($cliente->doc)->toBe('39053344705') // guardado só com dígitos
            ->and($cliente->type)->toBe(CustomerType::Individual);
    });
});

// ==================================================== nota, entrega, endereço

describe('nota do cliente, forma de entrega e endereço — BR-707', function () {
    it('captura o customer_note do pedido', function () {
        produtoWooMapeado(501, '100.00', '5');

        $r = app(ImportWooOrder::class)->handle(
            pedidoWoo(1101, [itemWoo(501, 1, '100.00')], [
                'total' => '100.00',
                'customer_note' => 'Entregar após as 18h, por favor.',
            ])
        );

        $pedido = Order::query()->find($r->orderId);
        expect($pedido->customer_note)->toBe('Entregar após as 18h, por favor.');
    });

    it('não grava customer_note em branco', function () {
        produtoWooMapeado(501, '100.00', '5');

        $r = app(ImportWooOrder::class)->handle(
            pedidoWoo(1109, [itemWoo(501, 1, '100.00')], ['total' => '100.00', 'customer_note' => ''])
        );

        expect(Order::query()->find($r->orderId)->customer_note)->toBeNull();
    });

    it('captura a forma de entrega do pedido', function () {
        produtoWooMapeado(501, '100.00', '5');

        $r = app(ImportWooOrder::class)->handle(
            pedidoWoo(1102, [itemWoo(501, 1, '100.00')], [
                'total' => '100.00',
                'shipping_lines' => [['method_title' => 'Loggi Express (Melhor Envio)']],
            ])
        );

        $pedido = Order::query()->find($r->orderId);
        expect($pedido->shipping_method)->toBe('Loggi Express (Melhor Envio)');
    });

    it('grava os endereços de cobrança e entrega do pedido, quando distintos', function () {
        produtoWooMapeado(501, '100.00', '5');

        $r = app(ImportWooOrder::class)->handle(
            pedidoWoo(1103, [itemWoo(501, 1, '100.00')], [
                'total' => '100.00',
                'billing' => [
                    'first_name' => 'Ana', 'last_name' => 'Silva', 'email' => 'ana@example.com',
                    'address_1' => 'Rua das Flores', 'city' => 'Florianópolis', 'state' => 'sc',
                    'postcode' => '88000000',
                ],
                'shipping' => [
                    'address_1' => 'Rua do Comércio', 'city' => 'São José', 'state' => 'sc',
                    'postcode' => '88100000',
                ],
            ])
        );

        $pedido = Order::query()->find($r->orderId);
        $enderecos = $pedido->addresses()->get()->keyBy('type');

        expect($enderecos)->toHaveCount(2)
            ->and($enderecos['billing']->street)->toBe('Rua das Flores')
            ->and($enderecos['billing']->city)->toBe('Florianópolis')
            ->and($enderecos['shipping']->street)->toBe('Rua do Comércio')
            ->and($enderecos['shipping']->city)->toBe('São José')
            ->and($enderecos['billing']->street)->not->toBe($enderecos['shipping']->street);
    });

    it('bloco shipping vazio não vira endereço, mas billing continua sendo gravado', function () {
        produtoWooMapeado(501, '100.00', '5');

        $r = app(ImportWooOrder::class)->handle(
            pedidoWoo(1104, [itemWoo(501, 1, '100.00')], [
                'total' => '100.00',
                'billing' => [
                    'first_name' => 'Ana', 'last_name' => 'Silva', 'email' => 'ana@example.com',
                    'address_1' => 'Rua das Flores', 'city' => 'Florianópolis', 'state' => 'SC',
                    'postcode' => '88000000',
                ],
                // Woo deixa `shipping` em branco quando a entrega é no
                // próprio endereço de cobrança — comportamento normal.
                'shipping' => [],
            ])
        );

        $pedido = Order::query()->find($r->orderId);
        $enderecos = $pedido->addresses()->get();

        expect($enderecos)->toHaveCount(1)
            ->and($enderecos->first()->type)->toBe('billing')
            ->and($enderecos->first()->street)->toBe('Rua das Flores');
    });

    it('grava created_at com a data real do pedido no site, nao com o instante da importacao', function () {
        produtoWooMapeado(501, '100.00', '5');

        $r = app(ImportWooOrder::class)->handle(
            pedidoWoo(1105, [itemWoo(501, 1, '100.00')], [
                'total' => '100.00',
                'date_created_gmt' => '2025-03-10T14:22:10',
            ])
        );

        $pedido = Order::query()->find($r->orderId);
        expect($pedido->created_at->toDateString())->toBe('2025-03-10');
    });

    it('--historico: completed grava delivered_at com date_completed do site, nao com now()', function () {
        produtoWooMapeado(501, '100.00', '5');

        $r = app(ImportWooOrder::class)->handle(
            pedidoWoo(1106, [itemWoo(501, 1, '100.00')], [
                'status' => 'completed',
                'total' => '100.00',
                'date_created_gmt' => '2025-03-10T14:22:10',
                'date_completed_gmt' => '2025-03-15T09:00:00',
            ]),
            historico: true,
        );

        $pedido = Order::query()->find($r->orderId);
        expect($pedido->status)->toBe(OrderStatus::Entregue)
            ->and($pedido->created_at->toDateString())->toBe('2025-03-10')
            ->and($pedido->delivered_at->toDateString())->toBe('2025-03-15');
    });
});

// ============================================================ webhook

describe('entrada por webhook', function () {
    it('aceita webhook assinado, guarda o bruto e cria o pedido', function () {
        produtoWooMapeado(501, '100.00', '5');

        $resposta = postWebhook(pedidoWoo(2001, [itemWoo(501, 1, '100.00')], ['total' => '100.00']));

        $resposta->assertStatus(202);

        // A fila é sync no teste: o job já processou.
        expect(Order::query()->where('channel_order_ref', '2001')->exists())->toBeTrue();

        $evento = WooWebhookEvent::query()->where('remote_id', '2001')->first();
        expect($evento)->not->toBeNull()
            ->and($evento->signature_valid)->toBeTrue()
            ->and($evento->processed_at)->not->toBeNull();
    });

    it('recusa e registra o webhook com assinatura inválida', function () {
        produtoWooMapeado(501, '100.00', '5');

        $resposta = postWebhook(pedidoWoo(2002, [itemWoo(501, 1, '100.00')], ['total' => '100.00']), secret: 'chave_errada');

        $resposta->assertStatus(401);
        expect(Order::query()->count())->toBe(0);

        $evento = WooWebhookEvent::query()->where('remote_id', '2002')->first();
        expect($evento)->not->toBeNull()
            ->and($evento->signature_valid)->toBeFalse();
    });

    it('responde 200 ao ping de ativação, sem criar pedido', function () {
        $resposta = postWebhook(['webhook_id' => 7], secret: null);

        $resposta->assertOk();
        expect(WooWebhookEvent::query()->where('topic', 'ping')->exists())->toBeTrue()
            ->and(Order::query()->count())->toBe(0);
    });

    it('processa o mesmo pedido uma vez só, ainda que o webhook repita', function () {
        produtoWooMapeado(501, '100.00', '5');
        $payload = pedidoWoo(2003, [itemWoo(501, 1, '100.00')], ['total' => '100.00']);

        postWebhook($payload)->assertStatus(202);
        postWebhook($payload)->assertStatus(202);

        expect(Order::query()->where('channel_order_ref', '2003')->count())->toBe(1);
    });

    it('webhook em tempo real: completed continua em rascunho sem reserva, mas com nota/entrega/endereço capturados', function () {
        produtoWooMapeado(501, '100.00', '5');

        postWebhook(pedidoWoo(2004, [itemWoo(501, 1, '100.00')], [
            'status' => 'completed',
            'total' => '100.00',
            'customer_note' => 'Presente — não incluir nota fiscal na caixa.',
            'shipping_lines' => [['method_title' => 'Retirada no ateliê']],
            'billing' => [
                'first_name' => 'Ana', 'last_name' => 'Silva', 'email' => 'ana@example.com',
                'address_1' => 'Rua das Flores', 'city' => 'Florianópolis', 'state' => 'SC',
                'postcode' => '88000000',
            ],
        ]))->assertStatus(202);

        $pedido = Order::query()->where('channel_order_ref', '2004')->firstOrFail();

        // Regressão do corte 4: pedido `completed` via webhook (nunca
        // `--historico`) continua entrando em rascunho, sem reserva.
        expect($pedido->status)->toBe(OrderStatus::Draft)
            ->and($pedido->customer_note)->toBe('Presente — não incluir nota fiscal na caixa.')
            ->and($pedido->shipping_method)->toBe('Retirada no ateliê')
            ->and($pedido->addresses()->where('type', 'billing')->exists())->toBeTrue();

        expect(StockReservation::query()->count())->toBe(0);
    });
});

// ============================================================ puxada

describe('entrada por puxada (pull)', function () {
    beforeEach(function () {
        config()->set('integrations.woocommerce', [
            'enabled' => true,
            'url' => 'https://loja.test',
            'key' => 'ck_teste',
            'secret' => 'cs_teste',
            'per_page' => 10,
            'timeout' => 5,
        ]);
    });

    it('puxa pedidos e cria os correspondentes no ERP', function () {
        produtoWooMapeado(501, '100.00', '10');

        Http::fake([
            '*/products?*' => Http::response([['id' => 1]]), // teste de conexão
            '*/orders?*page=1*' => Http::response([
                pedidoWoo(3001, [itemWoo(501, 1, '100.00')], ['total' => '100.00']),
            ]),
            '*' => Http::response([]),
        ]);

        test()->artisan('erp:woo:pull-orders --status=processing')->assertSuccessful();

        expect(Order::query()->where('channel_order_ref', '3001')->exists())->toBeTrue();
    });

    it('não duplica pedido ao puxar de novo', function () {
        produtoWooMapeado(501, '100.00', '10');

        Http::fake([
            '*/products?*' => Http::response([['id' => 1]]),
            '*/orders?*page=1*' => Http::response([
                pedidoWoo(3002, [itemWoo(501, 1, '100.00')], ['total' => '100.00']),
            ]),
            '*' => Http::response([]),
        ]);

        test()->artisan('erp:woo:pull-orders')->assertSuccessful();
        test()->artisan('erp:woo:pull-orders')->assertSuccessful();

        expect(Order::query()->where('channel_order_ref', '3002')->count())->toBe(1);
    });

    it('dry-run conta mas não grava', function () {
        produtoWooMapeado(501, '100.00', '10');

        Http::fake([
            '*/products?*' => Http::response([['id' => 1]]),
            '*/orders?*page=1*' => Http::response([
                pedidoWoo(3003, [itemWoo(501, 1, '100.00')], ['total' => '100.00']),
            ]),
            '*' => Http::response([]),
        ]);

        test()->artisan('erp:woo:pull-orders --dry-run')->assertSuccessful();

        expect(Order::query()->count())->toBe(0);
    });

    it('--historico: completed vira Entregue sem reserva nem movimento de estoque', function () {
        produtoWooMapeado(501, '100.00', '5');

        Http::fake([
            '*/products?*' => Http::response([['id' => 1]]),
            '*/orders?*page=1*' => Http::response([
                pedidoWoo(3004, [itemWoo(501, 1, '100.00')], ['status' => 'completed', 'total' => '100.00']),
            ]),
            '*' => Http::response([]),
        ]);

        test()->artisan('erp:woo:pull-orders --status=completed --historico')->assertSuccessful();

        $pedido = Order::query()->where('channel_order_ref', '3004')->firstOrFail();

        expect($pedido->status)->toBe(OrderStatus::Entregue)
            ->and($pedido->delivered_at)->not->toBeNull();

        expect(StockReservation::query()->where('reference_type', 'order')->where('reference_id', $pedido->id)->count())->toBe(0)
            ->and(InventoryMovement::query()->where('reference_type', 'order')->where('reference_id', $pedido->id)->count())->toBe(0);
    });

    it('--historico: processing continua reservando normalmente (regressão)', function () {
        produtoWooMapeado(501, '100.00', '10');

        Http::fake([
            '*/products?*' => Http::response([['id' => 1]]),
            '*/orders?*page=1*' => Http::response([
                pedidoWoo(3005, [itemWoo(501, 1, '100.00')], ['status' => 'processing', 'total' => '100.00']),
            ]),
            '*' => Http::response([]),
        ]);

        test()->artisan('erp:woo:pull-orders --status=processing --historico')->assertSuccessful();

        $pedido = Order::query()->where('channel_order_ref', '3005')->firstOrFail();

        expect($pedido->status)->toBe(OrderStatus::Confirmed);

        expect(StockReservation::query()
            ->where('reference_type', 'order')
            ->where('reference_id', $pedido->id)
            ->where('status', ReservationStatus::Active->value)
            ->exists())->toBeTrue();
    });
});

// ============================================ cancelamento do site (order.updated)

describe('cancelamento vindo do site', function () {
    it('reflete no ERP o cancelamento do site, liberando a reserva', function () {
        $produto = produtoWooMapeado(501, '100.00', '5');
        $import = app(ImportWooOrder::class);

        // Entra confirmado e reservado.
        $import->handle(pedidoWoo(2100, [itemWoo(501, 1, '100.00')], ['total' => '100.00']));
        $orderId = IntegrationMapping::localDe('order', 2100);

        // O site cancela: chega um order.updated com status cancelled.
        $r = $import->handle(pedidoWoo(2100, [itemWoo(501, 1, '100.00')], ['status' => 'cancelled', 'total' => '100.00']));

        expect($r->status)->toBe(ResultadoDaImportacao::CANCELADO)
            ->and(Order::query()->find($orderId)->status)->toBe(OrderStatus::Cancelled);

        // Reserva liberada; a peça nunca saiu, o on_hand fica intacto.
        $saldo = InventoryBalance::query()->where('product_id', $produto->id)->firstOrFail();
        expect($saldo->qty_reserved)->toBe('0.000')
            ->and($saldo->qty_on_hand)->toBe('5.000');
    });

    it('não empurra o cancelamento de volta ao Woo (anti-eco)', function () {
        produtoWooMapeado(501, '100.00', '5');
        $import = app(ImportWooOrder::class);
        $import->handle(pedidoWoo(2101, [itemWoo(501, 1, '100.00')], ['total' => '100.00']));

        Bus::fake([PushCancellationToWoo::class]);
        $import->handle(pedidoWoo(2101, [itemWoo(501, 1, '100.00')], ['status' => 'cancelled', 'total' => '100.00']));

        Bus::assertNotDispatched(PushCancellationToWoo::class);
    });

    it('cancelar duas vezes é no-op na segunda (idempotente)', function () {
        produtoWooMapeado(501, '100.00', '5');
        $import = app(ImportWooOrder::class);
        $import->handle(pedidoWoo(2102, [itemWoo(501, 1, '100.00')], ['total' => '100.00']));

        $cancelado = pedidoWoo(2102, [itemWoo(501, 1, '100.00')], ['status' => 'cancelled', 'total' => '100.00']);
        expect($import->handle($cancelado)->status)->toBe(ResultadoDaImportacao::CANCELADO)
            ->and($import->handle($cancelado)->status)->toBe(ResultadoDaImportacao::DUPLICADO);
    });

    it('não desfaz expedição: cancelamento do site já expedido vira conflito', function () {
        produtoWooMapeado(501, '100.00', '5');
        $import = app(ImportWooOrder::class);
        $import->handle(pedidoWoo(2103, [itemWoo(501, 1, '100.00')], ['total' => '100.00']));
        $orderId = IntegrationMapping::localDe('order', 2103);

        // Leva o pedido até expedido. Pedido do site exige NF-e autorizada
        // para expedir (BR-309, ADR-0025) — este teste é sobre o
        // cancelamento tardio, não sobre o fiscal, então a nota entra como
        // já autorizada em vez de o teste emitir uma.
        $pedido = Order::query()->findOrFail($orderId);
        $pedido->forceFill(['nfe_status' => 'authorized', 'invoice_authorized_at' => now()])->save();

        $f = app(FulfillmentService::class);
        $f->iniciarSeparacao($pedido);
        $f->embalar($pedido);
        $f->expedir($pedido);

        $r = $import->handle(pedidoWoo(2103, [itemWoo(501, 1, '100.00')], ['status' => 'cancelled', 'total' => '100.00']));

        expect($r->status)->toBe(ResultadoDaImportacao::CONFLITO)
            ->and(Order::query()->find($orderId)->status)->toBe(OrderStatus::Expedido);
    });
});
