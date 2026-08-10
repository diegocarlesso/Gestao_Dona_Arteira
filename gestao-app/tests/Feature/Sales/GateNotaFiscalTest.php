<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\SetProductPriceService;
use App\Modules\Fiscal\Events\InvoiceAuthorized;
use App\Modules\Fiscal\Events\InvoiceRejected;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Services\RecordMovementService;
use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Exceptions\PedidoInvalido;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\ConfirmOrderService;
use App\Modules\Sales\Services\FulfillmentService;

/*
|--------------------------------------------------------------------------
| Gate BR-309 — expedição exige NF-e autorizada (ADR-0025 §3)
|--------------------------------------------------------------------------
|
| Mercadoria não circula sem documento fiscal. O pedido do site não passa
| de Embalado a Expedido enquanto `nfe_status` não for `authorized` —
| coluna do próprio módulo Vendas, escrita pelo listener que ouve o Fiscal.
|
| O contra-teste importa tanto quanto o teste: pedido de balcão continua
| expedindo com `nfe_status` nulo. A BR-601 ("quando a NF-e é exigida")
| ainda é hipótese bloqueada no contador, e travar o balcão por uma
| suposição pararia a operação.
|
*/

beforeEach(function () {
    Location::factory()->padrao()->create();
});

function pedidoEmbaladoNoCanal(OrderChannel $canal): Order
{
    $produto = Product::factory()->create();
    app(SetProductPriceService::class)->handle($produto, PriceList::Retail, '100.00');

    $local = Location::query()->where('is_default', true)->firstOrFail();
    app(RecordMovementService::class)->handle($produto->id, $local->id, MovementType::ProductionOutput, '5');

    $pedido = Order::factory()->create(['channel' => $canal]);
    $pedido->items()->create([
        'product_id' => $produto->id,
        'qty' => '2',
        'unit_price' => '100.00',
        'discount' => '0.00',
    ]);

    app(ConfirmOrderService::class)->handle($pedido);

    $fulfillment = app(FulfillmentService::class);
    $fulfillment->iniciarSeparacao($pedido);

    return $fulfillment->embalar($pedido);
}

it('recusa expedir pedido do site sem NF-e autorizada', function () {
    $pedido = pedidoEmbaladoNoCanal(OrderChannel::WooCommerce);

    expect(fn () => app(FulfillmentService::class)->expedir($pedido))
        ->toThrow(PedidoInvalido::class, 'não pode ser expedido sem NF-e autorizada');

    // Nada avançou e nada saiu do estoque.
    expect($pedido->fresh()->status)->toBe(OrderStatus::Embalado);
});

it('recusa expedir pedido do site com a nota apenas pendente', function () {
    $pedido = pedidoEmbaladoNoCanal(OrderChannel::WooCommerce);
    $pedido->forceFill(['nfe_status' => 'pending'])->save();

    expect(fn () => app(FulfillmentService::class)->expedir($pedido))
        ->toThrow(PedidoInvalido::class, 'pendente de transmissão');
});

it('recusa expedir pedido do site com a nota rejeitada', function () {
    $pedido = pedidoEmbaladoNoCanal(OrderChannel::WooCommerce);
    $pedido->forceFill(['nfe_status' => 'rejected'])->save();

    expect(fn () => app(FulfillmentService::class)->expedir($pedido))
        ->toThrow(PedidoInvalido::class, 'rejeitada pela SEFAZ');
});

it('expede o pedido do site quando a nota está autorizada', function () {
    $pedido = pedidoEmbaladoNoCanal(OrderChannel::WooCommerce);
    $pedido->forceFill(['nfe_status' => 'authorized', 'invoice_authorized_at' => now()])->save();

    app(FulfillmentService::class)->expedir($pedido, 'BR123', 'Correios');

    expect($pedido->fresh()->status)->toBe(OrderStatus::Expedido);
});

it('expede o pedido de balcão independentemente de NF-e', function () {
    $pedido = pedidoEmbaladoNoCanal(OrderChannel::Erp);

    app(FulfillmentService::class)->expedir($pedido);

    expect($pedido->fresh()->status)->toBe(OrderStatus::Expedido)
        ->and($pedido->fresh()->nfe_status)->toBeNull();
});

// -------------------------------------------------- listener Fiscal → Vendas

it('marca o pedido ao ouvir InvoiceAuthorized', function () {
    $pedido = Order::factory()->create(['channel' => OrderChannel::WooCommerce]);
    $nota = Invoice::factory()->autorizada()->create(['order_id' => $pedido->id]);

    event(new InvoiceAuthorized($nota, $pedido->id));

    $pedido->refresh();
    expect($pedido->nfe_status)->toBe('authorized')
        ->and($pedido->invoice_authorized_at)->not->toBeNull();
});

it('marca o pedido ao ouvir InvoiceRejected, sem carimbar autorização', function () {
    $pedido = Order::factory()->create(['channel' => OrderChannel::WooCommerce]);
    $nota = Invoice::factory()->rejeitada()->create(['order_id' => $pedido->id]);

    event(new InvoiceRejected($nota, $pedido->id, 'Rejeição 778'));

    $pedido->refresh();
    expect($pedido->nfe_status)->toBe('rejected')
        ->and($pedido->invoice_authorized_at)->toBeNull();
});

it('o ciclo completo destrava a expedição: autorizada → expede', function () {
    $pedido = pedidoEmbaladoNoCanal(OrderChannel::WooCommerce);
    $nota = Invoice::factory()->autorizada()->create(['order_id' => $pedido->id]);

    // Antes do evento, travado.
    expect(fn () => app(FulfillmentService::class)->expedir($pedido))
        ->toThrow(PedidoInvalido::class);

    event(new InvoiceAuthorized($nota, $pedido->id));

    app(FulfillmentService::class)->expedir($pedido->fresh());

    expect($pedido->fresh()->status)->toBe(OrderStatus::Expedido);
});
