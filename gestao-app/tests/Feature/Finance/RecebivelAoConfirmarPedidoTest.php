<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\SetProductPriceService;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Integrations\WooCommerce\Services\ImportWooOrder;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Services\RecordMovementService;
use App\Modules\Sales\Services\ConfirmOrderService;
use App\Modules\Sales\Services\SaveOrderService;
use Database\Seeders\Finance\FinanceAccountSeeder;
use Database\Seeders\Finance\FinanceCategorySeeder;

/*
|--------------------------------------------------------------------------
| Pedido confirmado gera título a receber — BR-501, docs/12-Financeiro §3
|--------------------------------------------------------------------------
|
| O que promete: todo canal gera título ao confirmar; se o pedido já
| nasceu pago (`paid_at`), o título nasce já baixado, sem passo manual —
| "venda balcão à vista: título já nasce baixado".
|
*/

beforeEach(function () {
    Location::factory()->padrao()->create();
    $this->seed(FinanceCategorySeeder::class);
    $this->seed(FinanceAccountSeeder::class);
});

function produtoParaVenda(string $preco = '100.00', string $estoque = '5'): Product
{
    $produto = Product::factory()->create();
    app(SetProductPriceService::class)->handle($produto, PriceList::Retail, $preco);

    $local = Location::query()->where('is_default', true)->firstOrFail();
    app(RecordMovementService::class)->handle($produto->id, $local->id, MovementType::ProductionOutput, $estoque);

    return $produto;
}

it('balcão à vista: título nasce já baixado (settled)', function () {
    $produto = produtoParaVenda();

    $pedido = app(SaveOrderService::class)->handle(null, [
        'payment_method' => 'Dinheiro',
        'pago_agora' => true,
    ], [['product' => $produto->public_id, 'qty' => '1']]);

    app(ConfirmOrderService::class)->handle($pedido);

    $titulo = Receivable::query()->daOrigem('order', $pedido->id)->first();

    expect($titulo)->not->toBeNull()
        ->and($titulo->status)->toBe(Receivable::STATUS_SETTLED)
        ->and($titulo->amount)->toBe($pedido->fresh()->total);
});

it('balcão a prazo (sem marcar pago): título nasce aberto', function () {
    $produto = produtoParaVenda();

    $pedido = app(SaveOrderService::class)->handle(null, [], [
        ['product' => $produto->public_id, 'qty' => '1'],
    ]);

    app(ConfirmOrderService::class)->handle($pedido);

    $titulo = Receivable::query()->daOrigem('order', $pedido->id)->first();

    expect($titulo->status)->toBe(Receivable::STATUS_OPEN);
});

it('pedido Woo processing (já pago no site): título nasce já baixado', function () {
    $produto = produtoParaVenda('50.00');
    IntegrationMapping::registrar('product', $produto->id, 9001);

    $resultado = app(ImportWooOrder::class)->handle([
        'id' => 5001,
        'number' => '5001',
        'status' => 'processing',
        'customer_id' => 0,
        'total' => '50.00',
        'shipping_total' => '0.00',
        'payment_method_title' => 'Pix',
        'date_created_gmt' => '2026-08-01T10:00:00',
        'date_paid_gmt' => '2026-08-01T10:05:00',
        'billing' => ['first_name' => 'Ana', 'last_name' => 'Silva', 'email' => 'ana@example.com'],
        'line_items' => [[
            'id' => 1, 'name' => 'Peça', 'product_id' => 9001, 'variation_id' => 0,
            'quantity' => 1, 'price' => '50.00', 'subtotal' => '50.00', 'total' => '50.00', 'sku' => '',
        ]],
    ]);

    $titulo = Receivable::query()->daOrigem('order', $resultado->orderId)->first();

    expect($titulo->status)->toBe(Receivable::STATUS_SETTLED);
});

it('pedido Woo on-hold (aguardando compensação): título nasce aberto', function () {
    $produto = produtoParaVenda('50.00');
    IntegrationMapping::registrar('product', $produto->id, 9002);

    $resultado = app(ImportWooOrder::class)->handle([
        'id' => 5002,
        'number' => '5002',
        'status' => 'on-hold',
        'customer_id' => 0,
        'total' => '50.00',
        'shipping_total' => '0.00',
        'payment_method_title' => 'Boleto',
        'billing' => ['first_name' => 'Ana', 'last_name' => 'Silva', 'email' => 'ana2@example.com'],
        'line_items' => [[
            'id' => 1, 'name' => 'Peça', 'product_id' => 9002, 'variation_id' => 0,
            'quantity' => 1, 'price' => '50.00', 'subtotal' => '50.00', 'total' => '50.00', 'sku' => '',
        ]],
    ]);

    $titulo = Receivable::query()->daOrigem('order', $resultado->orderId)->first();

    expect($titulo->status)->toBe(Receivable::STATUS_OPEN);
});
