<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\SetProductPriceService;
use App\Modules\Fiscal\Contracts\NfeGatewayInterface;
use App\Modules\Fiscal\DTO\NfeTransmissionResult;
use App\Modules\Fiscal\Enums\InvoiceStatus;
use App\Modules\Fiscal\Events\InvoiceAuthorized;
use App\Modules\Fiscal\Events\InvoiceRejected;
use App\Modules\Fiscal\Jobs\EmitNfeForOrder;
use App\Modules\Fiscal\Models\FiscalSeries;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\TaxProfile;
use App\Modules\Fiscal\Services\BuildInvoiceFromOrder;
use App\Modules\Fiscal\Services\Gateways\NullNfeGateway;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Services\RecordMovementService;
use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\ConfirmOrderService;
use App\Modules\Sales\Services\RegisterChannelOrderService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Emissão de NF-e — docs/14, ADR-0025 (P0.2 + P0.3)
|--------------------------------------------------------------------------
|
| O que este corte promete: pedido do site confirmado dispara, em fila, a
| montagem de uma NF-e — numerada com lock (BR-602), com CFOP/CSOSN vindos
| da matriz de perfis (docs/13 §3) e com pré-validação de cadastro
| (BR-001/BR-606). A transmissão real fica atrás do `NfeGatewayInterface`;
| a implementação ativa é a Null, que devolve `pending` e **não** finge
| autorização.
|
| O que este corte NÃO promete: XML assinado, SEFAZ de verdade, DANFE.
| Nenhum teste aqui deve dar a impressão contrária.
|
*/

beforeEach(function () {
    Location::factory()->padrao()->create();

    // O ambiente fiscal mínimo: flag ligada, série cadastrada, UF do
    // emitente definida. Nenhum dos três tem default utilizável de
    // propósito (ADR-0025 §4) — a ausência é que é o estado normal.
    config()->set('fiscal.nfe.enabled', true);
    config()->set('fiscal.nfe.uf_emitente', 'RS');
    FiscalSeries::factory()->create();
});

/** Produto vendável com o cadastro fiscal completo (BR-606) e saldo. */
function produtoFaturavel(string $preco = '100.00', string $estoque = '5'): Product
{
    $produto = Product::factory()->comDadosFiscais()->create();
    app(SetProductPriceService::class)->handle($produto, PriceList::Retail, $preco);

    $local = Location::query()->where('is_default', true)->firstOrFail();
    app(RecordMovementService::class)->handle($produto->id, $local->id, MovementType::ProductionOutput, $estoque);

    return $produto;
}

/** Cliente apto a receber nota: com CPF e com endereço. */
function clienteFaturavel(string $uf = 'RS'): Customer
{
    $cliente = Customer::factory()->comCpf()->create();

    CustomerAddress::factory()->create([
        'customer_id' => $cliente->id,
        'state' => $uf,
        'is_default_shipping' => true,
    ]);

    return $cliente;
}

/** Um pedido do site, confirmado — o gatilho do ADR-0025 §2. */
function pedidoDoSiteConfirmado(?Customer $cliente = null, ?Product $produto = null): Order
{
    $produto ??= produtoFaturavel();
    $cliente ??= clienteFaturavel();

    $resultado = app(RegisterChannelOrderService::class)->handle(
        channel: OrderChannel::WooCommerce,
        customerId: $cliente->id,
        channelOrderRef: (string) fake()->unique()->numberBetween(1000, 9999),
        itens: [['product_id' => $produto->id, 'qty' => '2', 'unit_price' => '100.00']],
        channelTotal: '200.00',
        tentarReservar: true,
    );

    return Order::query()->findOrFail($resultado->orderId);
}

/** O perfil da matriz para venda dentro do estado, a PF (docs/13 §3). */
function perfilDeVendaLocal(): TaxProfile
{
    return TaxProfile::factory()->create();
}

// -------------------------------------------------- gatilho automático (P0.3)

describe('gatilho', function () {
    it('enfileira a emissão quando um pedido do site é confirmado', function () {
        Bus::fake([EmitNfeForOrder::class]);

        $pedido = pedidoDoSiteConfirmado();

        Bus::assertDispatched(
            EmitNfeForOrder::class,
            fn (EmitNfeForOrder $job): bool => $job->orderId === $pedido->id
        );
    });

    it('não enfileira nada para pedido de balcão', function () {
        // BR-601 ainda é hipótese para o balcão — o filtro de canal existe
        // para não emitir nota que talvez não seja exigida.
        Bus::fake([EmitNfeForOrder::class]);

        $produto = produtoFaturavel();
        $pedido = Order::factory()->create(['channel' => OrderChannel::Erp]);
        $pedido->items()->create([
            'product_id' => $produto->id,
            'qty' => '1',
            'unit_price' => '100.00',
            'discount' => '0.00',
        ]);

        app(ConfirmOrderService::class)->handle($pedido);

        Bus::assertNotDispatched(EmitNfeForOrder::class);
    });

    it('fica quieto com a emissão desligada na config', function () {
        config()->set('fiscal.nfe.enabled', false);
        Bus::fake([EmitNfeForOrder::class]);

        pedidoDoSiteConfirmado();

        Bus::assertNotDispatched(EmitNfeForOrder::class);
    });
});

// -------------------------------------------------- montagem (P0.2)

describe('montagem da nota', function () {
    it('monta a nota com número, perfil e total quando o cadastro está completo', function () {
        $pedido = pedidoDoSiteConfirmado();
        $perfil = perfilDeVendaLocal();

        (new EmitNfeForOrder($pedido->id))->handle(
            app(BuildInvoiceFromOrder::class),
            app(NfeGatewayInterface::class),
        );

        $nota = Invoice::query()->where('order_id', $pedido->id)->firstOrFail();

        expect($nota->number)->toBe(1)
            ->and($nota->tax_profile_id)->toBe($perfil->id)
            ->and($nota->total)->toBe('200.00')
            ->and($nota->series_id)->not->toBeNull();
    });

    it('registra a pendência sem queimar número quando não há perfil fiscal', function () {
        // O estado inicial de todo ambiente: `tax_profiles` vazia, porque a
        // doc 13 está bloqueada no contador. Não pode estourar a fila.
        $pedido = pedidoDoSiteConfirmado();

        (new EmitNfeForOrder($pedido->id))->handle(
            app(BuildInvoiceFromOrder::class),
            app(NfeGatewayInterface::class),
        );

        $nota = Invoice::query()->where('order_id', $pedido->id)->firstOrFail();

        expect($nota->status)->toBe(InvoiceStatus::Pending)
            ->and($nota->number)->toBeNull()
            ->and($nota->rejection_reason)->toContain('tax_profiles');

        // O contador da série não andou: número não se queima por cadastro
        // incompleto (BR-602).
        expect(FiscalSeries::query()->firstOrFail()->next_number)->toBe(1);
    });

    it('registra a pendência quando o produto está sem NCM (BR-606)', function () {
        perfilDeVendaLocal();

        $produto = Product::factory()->create(); // sem dados fiscais
        app(SetProductPriceService::class)->handle($produto, PriceList::Retail, '100.00');
        $local = Location::query()->where('is_default', true)->firstOrFail();
        app(RecordMovementService::class)->handle($produto->id, $local->id, MovementType::ProductionOutput, '5');

        $pedido = pedidoDoSiteConfirmado(produto: $produto);

        (new EmitNfeForOrder($pedido->id))->handle(
            app(BuildInvoiceFromOrder::class),
            app(NfeGatewayInterface::class),
        );

        $nota = Invoice::query()->where('order_id', $pedido->id)->firstOrFail();

        expect($nota->status)->toBe(InvoiceStatus::Pending)
            ->and($nota->number)->toBeNull()
            // A mensagem tem de dizer QUAL produto e O QUE fazer.
            ->and($nota->rejection_reason)->toContain($produto->sku)
            ->and($nota->rejection_reason)->toContain('sem NCM');
    });

    it('registra a pendência quando o cliente está sem documento (BR-001)', function () {
        perfilDeVendaLocal();

        $cliente = Customer::factory()->create(); // sem CPF
        CustomerAddress::factory()->create(['customer_id' => $cliente->id, 'state' => 'RS']);

        $pedido = pedidoDoSiteConfirmado(cliente: $cliente);

        (new EmitNfeForOrder($pedido->id))->handle(
            app(BuildInvoiceFromOrder::class),
            app(NfeGatewayInterface::class),
        );

        expect(Invoice::query()->where('order_id', $pedido->id)->firstOrFail()->rejection_reason)
            ->toContain('CPF/CNPJ');
    });

    it('escolhe o perfil de fora do estado quando a entrega é interestadual', function () {
        // CFOP 5xxx vs 6xxx — o erro que a matriz existe para evitar.
        TaxProfile::factory()->create();               // dentro da UF, 5101
        $fora = TaxProfile::factory()->foraDaUf()->create(); // fora da UF, 6101

        $pedido = pedidoDoSiteConfirmado(cliente: clienteFaturavel('SP'));

        (new EmitNfeForOrder($pedido->id))->handle(
            app(BuildInvoiceFromOrder::class),
            app(NfeGatewayInterface::class),
        );

        expect(Invoice::query()->where('order_id', $pedido->id)->firstOrFail()->tax_profile_id)
            ->toBe($fora->id);
    });

    it('ignora perfil com vigência expirada', function () {
        TaxProfile::factory()->expirado()->create();

        $pedido = pedidoDoSiteConfirmado();

        (new EmitNfeForOrder($pedido->id))->handle(
            app(BuildInvoiceFromOrder::class),
            app(NfeGatewayInterface::class),
        );

        expect(Invoice::query()->where('order_id', $pedido->id)->firstOrFail()->number)->toBeNull();
    });
});

// -------------------------------------------------- numeração (BR-602)

describe('numeração', function () {
    it('dá números sequenciais e nunca repete', function () {
        perfilDeVendaLocal();
        $produto = produtoFaturavel('100.00', '50');

        $numeros = [];

        foreach (range(1, 3) as $i) {
            $pedido = pedidoDoSiteConfirmado(cliente: clienteFaturavel(), produto: $produto);

            (new EmitNfeForOrder($pedido->id))->handle(
                app(BuildInvoiceFromOrder::class),
                app(NfeGatewayInterface::class),
            );

            $numeros[] = Invoice::query()->where('order_id', $pedido->id)->firstOrFail()->number;
        }

        expect($numeros)->toBe([1, 2, 3])
            ->and(array_unique($numeros))->toHaveCount(3);
    });

    it('reprocessar o mesmo pedido não cria segunda nota nem gasta número', function () {
        perfilDeVendaLocal();
        $pedido = pedidoDoSiteConfirmado();
        $job = new EmitNfeForOrder($pedido->id);

        $job->handle(app(BuildInvoiceFromOrder::class), app(NfeGatewayInterface::class));
        $job->handle(app(BuildInvoiceFromOrder::class), app(NfeGatewayInterface::class));

        expect(Invoice::query()->where('order_id', $pedido->id)->count())->toBe(1)
            ->and(Invoice::query()->where('order_id', $pedido->id)->firstOrFail()->number)->toBe(1)
            ->and(FiscalSeries::query()->firstOrFail()->next_number)->toBe(2);
    });

    it('a série só existe no ambiente em que foi cadastrada', function () {
        // Homologação e produção têm numerações independentes na SEFAZ —
        // compartilhar o contador furaria a sequência fiscal de verdade.
        perfilDeVendaLocal();
        config()->set('fiscal.nfe.environment', 'producao');

        $pedido = pedidoDoSiteConfirmado();

        (new EmitNfeForOrder($pedido->id))->handle(
            app(BuildInvoiceFromOrder::class),
            app(NfeGatewayInterface::class),
        );

        expect(Invoice::query()->where('order_id', $pedido->id)->firstOrFail()->rejection_reason)
            ->toContain('fiscal_series');
    });
});

// -------------------------------------------------- transmissão

describe('transmissão', function () {
    it('com o gateway Null, a nota fica pendente e NENHUM evento é disparado', function () {
        // A garantia central desta passada: nada aqui deve dar a impressão
        // de que a SEFAZ respondeu.
        Event::fake([InvoiceAuthorized::class, InvoiceRejected::class]);
        perfilDeVendaLocal();
        $pedido = pedidoDoSiteConfirmado();

        (new EmitNfeForOrder($pedido->id))->handle(
            app(BuildInvoiceFromOrder::class),
            app(NfeGatewayInterface::class),
        );

        $nota = Invoice::query()->where('order_id', $pedido->id)->firstOrFail();

        expect($nota->status)->toBe(InvoiceStatus::Pending)
            ->and($nota->access_key)->toBeNull()
            ->and($nota->rejection_reason)->toContain('sped-nfe ainda não integrado');

        Event::assertNotDispatched(InvoiceAuthorized::class);
        Event::assertNotDispatched(InvoiceRejected::class);
    });

    it('o bind ativo do container é mesmo o gateway Null', function () {
        expect(app(NfeGatewayInterface::class))->toBeInstanceOf(NullNfeGateway::class);
    });

    it('com um gateway que autoriza, grava chave/protocolo e dispara InvoiceAuthorized', function () {
        Event::fake([InvoiceAuthorized::class]);
        perfilDeVendaLocal();
        $pedido = pedidoDoSiteConfirmado();

        app()->bind(NfeGatewayInterface::class, GatewayQueAutoriza::class);

        (new EmitNfeForOrder($pedido->id))->handle(
            app(BuildInvoiceFromOrder::class),
            app(NfeGatewayInterface::class),
        );

        $nota = Invoice::query()->where('order_id', $pedido->id)->firstOrFail();

        expect($nota->status)->toBe(InvoiceStatus::Authorized)
            ->and($nota->access_key)->toBe(GatewayQueAutoriza::CHAVE)
            ->and($nota->protocol)->toBe(GatewayQueAutoriza::PROTOCOLO);

        Event::assertDispatched(
            InvoiceAuthorized::class,
            fn (InvoiceAuthorized $e): bool => $e->orderId === $pedido->id && $e->invoice->is($nota)
        );
    });

    it('com um gateway que rejeita, guarda o motivo e mantém o número', function () {
        Event::fake([InvoiceRejected::class]);
        perfilDeVendaLocal();
        $pedido = pedidoDoSiteConfirmado();

        app()->bind(NfeGatewayInterface::class, GatewayQueRejeita::class);

        (new EmitNfeForOrder($pedido->id))->handle(
            app(BuildInvoiceFromOrder::class),
            app(NfeGatewayInterface::class),
        );

        $nota = Invoice::query()->where('order_id', $pedido->id)->firstOrFail();

        expect($nota->status)->toBe(InvoiceStatus::Rejected)
            ->and($nota->rejection_reason)->toBe(GatewayQueRejeita::MOTIVO)
            // Rejeição não queima número: reemite-se com o mesmo (BR-602).
            ->and($nota->number)->toBe(1);

        Event::assertDispatched(InvoiceRejected::class);
    });

    it('nota já autorizada não é retransmitida', function () {
        perfilDeVendaLocal();
        $pedido = pedidoDoSiteConfirmado();

        app()->bind(NfeGatewayInterface::class, GatewayQueAutoriza::class);
        $job = new EmitNfeForOrder($pedido->id);
        $job->handle(app(BuildInvoiceFromOrder::class), app(NfeGatewayInterface::class));

        // Segunda passada com um gateway que rejeitaria: se a nota fosse
        // retransmitida, o status mudaria — e nota autorizada é imutável.
        app()->bind(NfeGatewayInterface::class, GatewayQueRejeita::class);
        $job->handle(app(BuildInvoiceFromOrder::class), app(NfeGatewayInterface::class));

        expect(Invoice::query()->where('order_id', $pedido->id)->firstOrFail()->status)
            ->toBe(InvoiceStatus::Authorized);
    });
});

/**
 * Dublês de teste do gateway — a única forma honesta de exercitar o caminho
 * "autorizada" enquanto o emissor real não existe. Ficam no arquivo de
 * teste, e não em `app/`, para que ninguém possa bindá-los por engano.
 */
class GatewayQueAutoriza implements NfeGatewayInterface
{
    public const CHAVE = '43260812345678000199550010000000011000000017';

    public const PROTOCOLO = '143260000000001';

    public function transmitir(Invoice $invoice): NfeTransmissionResult
    {
        return NfeTransmissionResult::autorizado(self::CHAVE, self::PROTOCOLO, 'nfe/2026/08/teste.xml');
    }
}

class GatewayQueRejeita implements NfeGatewayInterface
{
    public const MOTIVO = 'Rejeição 778: NCM informado não consta na tabela — corrija o NCM do produto.';

    public function transmitir(Invoice $invoice): NfeTransmissionResult
    {
        return NfeTransmissionResult::rejeitado(self::MOTIVO);
    }
}
