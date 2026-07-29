<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\SetProductPriceService;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Integrations\WooCommerce\Enums\TipoDeDivergencia;
use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Integrations\WooCommerce\Models\WooReconciliationFinding;
use App\Modules\Integrations\WooCommerce\Models\WooReconciliationRun;
use App\Modules\Integrations\WooCommerce\Models\WooWebhookEvent;
use App\Modules\Integrations\WooCommerce\Services\ImportWooOrder;
use App\Modules\Integrations\WooCommerce\Services\ReconcileWooOrders;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Services\RecordMovementService;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Models\Order;
use Database\Seeders\Identity\RolePermissionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Reconciliação diária de pedidos Woo→ERP — ADR-0025, docs/16 §5
|--------------------------------------------------------------------------
|
| A rede de segurança: importa o pedido que o webhook perdeu e denuncia o
| total editado no wp-admin (BR-702), sem nunca mutar o pedido do ERP
| (BR-304). Helpers locais (prefixo recon) para não acoplar ao PedidoWooTest.
|
*/

beforeEach(function () {
    Location::factory()->padrao()->create();
    config()->set('integrations.woocommerce', [
        'enabled' => true,
        'url' => 'https://loja.test',
        'key' => 'ck_teste',
        'secret' => 'cs_teste',
        'per_page' => 10,
        'timeout' => 5,
    ]);
});

/** Um produto do ERP com preço, estoque opcional e mapeamento id-do-Woo. */
function reconProduto(int $wooId, string $preco = '100.00', string $estoque = '0'): Product
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
 * @return array<string, mixed>
 */
function reconItem(int $wooProductId, int $qty, string $price): array
{
    return [
        'id' => fake()->unique()->numberBetween(1, 99999),
        'name' => "Produto {$wooProductId}",
        'product_id' => $wooProductId,
        'variation_id' => 0,
        'quantity' => $qty,
        'price' => $price,
        'subtotal' => bcmul((string) $qty, $price, 2),
        'total' => bcmul((string) $qty, $price, 2),
        'sku' => '',
    ];
}

/**
 * @param  list<array<string, mixed>>  $lineItems
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function reconPedido(int $id, array $lineItems, array $extra = []): array
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
 * Faz o Woo devolver esta lista de pedidos numa página só.
 *
 * @param  list<array<string, mixed>>  $orders
 */
function fakeWooOrders(array $orders): void
{
    Http::fake([
        '*/orders?*page=1*' => Http::response($orders),
        '*' => Http::response([]),
    ]);
}

// ======================================================= âncora de checksum

it('congela o total como âncora de checksum na importação', function () {
    reconProduto(501, '100.00', '5');

    app(ImportWooOrder::class)->handle(reconPedido(7001, [reconItem(501, 2, '100.00')], ['total' => '200.00']));

    expect(IntegrationMapping::checksumDe('order', 7001))->toBe('200.00');
});

// ======================================================= pedido ausente

it('importa o pedido que o webhook perdeu e conta como importado', function () {
    reconProduto(501, '100.00', '5');
    fakeWooOrders([reconPedido(7002, [reconItem(501, 1, '100.00')], ['total' => '100.00'])]);

    $run = app(ReconcileWooOrders::class)->handle();

    expect(Order::query()->where('channel_order_ref', '7002')->exists())->toBeTrue()
        ->and($run->orders_missing_imported)->toBe(1)
        ->and($run->orders_divergent)->toBe(0)
        ->and($run->situacao())->toBe('ok');

    // Deixou a trilha natural no log de eventos (order.pulled).
    expect(WooWebhookEvent::query()->where('remote_id', '7002')->where('topic', 'order.pulled')->exists())->toBeTrue();
});

// ======================================================= divergência de valor

it('detecta divergência de total e não muta o pedido do ERP (BR-304)', function () {
    reconProduto(501, '100.00', '5');
    app(ImportWooOrder::class)->handle(reconPedido(7003, [reconItem(501, 1, '100.00')], ['total' => '100.00']));
    $orderId = IntegrationMapping::localDe('order', 7003);

    // O site edita o total para 150 (wp-admin).
    fakeWooOrders([reconPedido(7003, [reconItem(501, 1, '100.00')], ['total' => '150.00'])]);
    $run = app(ReconcileWooOrders::class)->handle();

    $achado = WooReconciliationFinding::query()->where('remote_id', '7003')->first();
    expect($achado)->not->toBeNull()
        ->and($achado->kind)->toBe(TipoDeDivergencia::ChecksumMismatch)
        ->and($achado->baseline_checksum)->toBe('100.00')
        ->and($achado->remote_checksum)->toBe('150.00')
        ->and($achado->resolved_at)->toBeNull()
        ->and($run->orders_divergent)->toBe(1);

    // Pedido do ERP congelado; âncora intacta (não adota o total do site).
    expect(Order::query()->find($orderId)->total)->toBe('100.00')
        ->and(IntegrationMapping::checksumDe('order', 7003))->toBe('100.00');
});

it('acumula ocorrências da mesma divergência sem duplicar linha', function () {
    reconProduto(501, '100.00', '5');
    app(ImportWooOrder::class)->handle(reconPedido(7004, [reconItem(501, 1, '100.00')], ['total' => '100.00']));

    fakeWooOrders([reconPedido(7004, [reconItem(501, 1, '100.00')], ['total' => '150.00'])]);
    app(ReconcileWooOrders::class)->handle();
    app(ReconcileWooOrders::class)->handle();

    $achados = WooReconciliationFinding::query()->where('remote_id', '7004')->get();
    expect($achados)->toHaveCount(1)
        ->and($achados->first()->occurrences)->toBe(2)
        ->and($achados->first()->recorrente())->toBeTrue();
});

it('auto-resolve o achado quando o total volta ao valor congelado', function () {
    reconProduto(501, '100.00', '5');
    app(ImportWooOrder::class)->handle(reconPedido(7005, [reconItem(501, 1, '100.00')], ['total' => '100.00']));

    // Http::fake acumula stubs (o 1º que casa vence), então aqui a fake é
    // instalada uma vez e lê um valor mutável — para o 2º reconcile ver o
    // total já corrigido, não o divergente da 1ª passada.
    $pedidos = [reconPedido(7005, [reconItem(501, 1, '100.00')], ['total' => '150.00'])];
    // Closure por referência (não arrow fn, que captura por valor): mutar
    // $pedidos entre as passadas muda o que a fake devolve.
    Http::fake(function (Request $request) use (&$pedidos) {
        return Http::response(str_contains($request->url(), 'page=1') ? $pedidos : []);
    });

    // Diverge…
    app(ReconcileWooOrders::class)->handle();

    // …e volta ao valor congelado: resolve sozinho, sem ação humana.
    $pedidos = [reconPedido(7005, [reconItem(501, 1, '100.00')], ['total' => '100.00'])];
    $run = app(ReconcileWooOrders::class)->handle();

    $achado = WooReconciliationFinding::query()->where('remote_id', '7005')->first();
    expect($achado->resolved_at)->not->toBeNull()
        ->and($achado->resolved_by)->toBeNull()
        ->and($run->findings_resolved)->toBe(1);
});

it('adota o total corrente como linha de base quando não há âncora (backfill)', function () {
    reconProduto(501, '100.00', '5');
    app(ImportWooOrder::class)->handle(reconPedido(7006, [reconItem(501, 1, '100.00')], ['total' => '100.00']));

    // Simula pedido importado antes deste recurso: mapeado, sem checksum.
    IntegrationMapping::query()
        ->where('entity_type', 'order')->where('remote_id', '7006')
        ->update(['checksum' => null]);

    fakeWooOrders([reconPedido(7006, [reconItem(501, 1, '100.00')], ['total' => '120.00'])]);
    $run = app(ReconcileWooOrders::class)->handle();

    // Sem achado; a âncora passa a ser o corrente (não acusa o que não tinha base).
    expect(WooReconciliationFinding::query()->where('remote_id', '7006')->exists())->toBeFalse()
        ->and(IntegrationMapping::checksumDe('order', 7006))->toBe('120.00')
        ->and($run->orders_divergent)->toBe(0);
});

// ======================================================= cancelamento perdido

it('reflete o cancelamento que o webhook perdeu, sem virar achado', function () {
    reconProduto(501, '100.00', '5');
    app(ImportWooOrder::class)->handle(reconPedido(7007, [reconItem(501, 1, '100.00')], ['total' => '100.00']));
    $orderId = IntegrationMapping::localDe('order', 7007);

    fakeWooOrders([reconPedido(7007, [reconItem(501, 1, '100.00')], ['status' => 'cancelled', 'total' => '100.00'])]);
    $run = app(ReconcileWooOrders::class)->handle();

    expect(Order::query()->find($orderId)->status)->toBe(OrderStatus::Cancelled)
        ->and(WooReconciliationFinding::query()->where('remote_id', '7007')->exists())->toBeFalse()
        ->and($run->orders_divergent)->toBe(0);
});

// ======================================================= heartbeat / desligado

it('não cria rodada quando a integração está desligada', function () {
    config()->set('integrations.woocommerce.enabled', false);

    test()->artisan('erp:woo:reconcile-orders')->assertSuccessful();

    expect(WooReconciliationRun::query()->count())->toBe(0);
});

it('registra a rodada como heartbeat', function () {
    fakeWooOrders([]);

    app(ReconcileWooOrders::class)->handle();

    $run = WooReconciliationRun::ultima();
    expect($run)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->situacao())->toBe('ok')
        ->and($run->window_days)->toBe(7);
});

// ======================================================= resolver (painel)

it('resolve manualmente e re-baseliza a âncora para o novo total', function () {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->comDoisFatores()->create()->assignRoleEnum(Role::Admin);

    reconProduto(501, '100.00', '5');
    app(ImportWooOrder::class)->handle(reconPedido(7008, [reconItem(501, 1, '100.00')], ['total' => '100.00']));

    fakeWooOrders([reconPedido(7008, [reconItem(501, 1, '100.00')], ['total' => '150.00'])]);
    app(ReconcileWooOrders::class)->handle();

    $achado = WooReconciliationFinding::query()->where('remote_id', '7008')->firstOrFail();

    actingAs($admin)
        ->post("/integracoes/divergencias/{$achado->id}/resolver")
        ->assertRedirect();

    // Achado resolvido pelo usuário e âncora aceita o novo total.
    expect($achado->fresh()->resolved_at)->not->toBeNull()
        ->and($achado->fresh()->resolved_by)->toBe($admin->id)
        ->and(IntegrationMapping::checksumDe('order', 7008))->toBe('150.00');

    // A próxima rodada não reabre (a base agora é 150).
    fakeWooOrders([reconPedido(7008, [reconItem(501, 1, '100.00')], ['total' => '150.00'])]);
    app(ReconcileWooOrders::class)->handle();

    expect(WooReconciliationFinding::query()->where('remote_id', '7008')->whereNull('resolved_at')->count())->toBe(0);
});

it('mostra os achados abertos e a última reconciliação no painel', function () {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->comDoisFatores()->create()->assignRoleEnum(Role::Admin);

    reconProduto(501, '100.00', '5');
    app(ImportWooOrder::class)->handle(reconPedido(7009, [reconItem(501, 1, '100.00')], ['total' => '100.00']));

    fakeWooOrders([reconPedido(7009, [reconItem(501, 1, '100.00')], ['total' => '150.00'])]);
    app(ReconcileWooOrders::class)->handle();

    actingAs($admin)
        ->get('/integracoes')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('integrations/panel')
            ->where('achados.abertos', 1)
            ->where('achados.lista.0.remote_id', '7009')
            ->where('ultimaReconciliacao.orders_divergent', 1)
            ->where('ultimaReconciliacao.situacao', 'ok')
        );
});
