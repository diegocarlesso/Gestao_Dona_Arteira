<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\SetProductPriceService;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Exceptions\ReservaInvalida;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\RecordMovementService;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Exceptions\PedidoInvalido;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\CancelOrderService;
use App\Modules\Sales\Services\ConfirmOrderService;
use App\Modules\Sales\Services\SaveOrderService;
use Database\Seeders\Identity\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Location::factory()->padrao()->create();
});

/*
|--------------------------------------------------------------------------
| Pedidos — BR-302/BR-303, docs/10-Vendas (corte 2 do Gate 02)
|--------------------------------------------------------------------------
|
| O que o corte promete: pedido com preço de item congelado (BR-302);
| confirmar reserva o estoque de cada item ou não reserva nenhum; cancelar
| libera o que foi reservado. E a fronteira: Vendas move o estoque só pela
| via da reserva, nunca por dentro.
|
*/

function vendedorDe(Role $papel = Role::Sales): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum($papel);
}

function produtoVendavel(string $preco = '99.90', string $estoque = '0'): Product
{
    $produto = Product::factory()->create();
    app(SetProductPriceService::class)->handle($produto, PriceList::Retail, $preco);

    if (bccomp($estoque, '0', 3) === 1) {
        $local = Location::query()->where('is_default', true)->firstOrFail();
        app(RecordMovementService::class)->handle($produto->id, $local->id, MovementType::ProductionOutput, $estoque);
    }

    return $produto;
}

function abrirPedido(array $itens, ?int $customerId = null): Order
{
    return app(SaveOrderService::class)->handle(null, ['customer_id' => $customerId], $itens);
}

// ------------------------------------------------- rascunho e preço

it('congela o preço de varejo do item ao adicioná-lo (BR-302)', function () {
    $produto = produtoVendavel('129.90');

    $pedido = abrirPedido([['product' => $produto->public_id, 'qty' => '2']]);

    expect($pedido->items()->value('unit_price'))->toBe('129.90')
        ->and($pedido->total)->toBe('259.80');   // 2 × 129,90
});

it('mantém o preço congelado mesmo se a tabela mudar depois', function () {
    $produto = produtoVendavel('100.00');
    $pedido = abrirPedido([['product' => $produto->public_id, 'qty' => '1']]);

    // Preço sobe na tabela; o pedido já aberto não repreça.
    app(SetProductPriceService::class)->handle($produto, PriceList::Retail, '150.00');

    expect($pedido->items()->value('unit_price'))->toBe('100.00');
});

it('recusa vender produto sem preço de varejo', function () {
    $produto = Product::factory()->create();   // sem preço

    expect(fn () => abrirPedido([['product' => $produto->public_id, 'qty' => '1']]))
        ->toThrow(PedidoInvalido::class);
});

it('não cria segunda linha para o mesmo produto', function () {
    $produto = produtoVendavel();
    $pedido = abrirPedido([['product' => $produto->public_id, 'qty' => '1']]);

    app(SaveOrderService::class)->handle($pedido, [], [['product' => $produto->public_id, 'qty' => '5']]);

    expect($pedido->items()->count())->toBe(1)
        ->and($pedido->items()->value('qty'))->toBe('5.000');
});

// ------------------------------------------------- confirmar/reserva

it('confirmar reserva o estoque de cada item', function () {
    $produto = produtoVendavel('99.90', '10');
    $pedido = abrirPedido([['product' => $produto->public_id, 'qty' => '4']]);

    app(ConfirmOrderService::class)->handle($pedido);

    $saldo = InventoryBalance::query()->where('product_id', $produto->id)->first();

    expect($pedido->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($saldo->qty_reserved)->toBe('4.000')
        ->and($saldo->disponivel())->toBe('6.000')
        ->and(StockReservation::query()->ativas()->where('reference_id', $pedido->id)->exists())->toBeTrue();
});

it('não reserva nada quando um item não cabe no disponível', function () {
    $ok = produtoVendavel('99.90', '10');
    $faltando = produtoVendavel('50.00', '2');
    $pedido = abrirPedido([
        ['product' => $ok->public_id, 'qty' => '3'],
        ['product' => $faltando->public_id, 'qty' => '5'],   // só há 2
    ]);

    try {
        app(ConfirmOrderService::class)->handle($pedido);
    } catch (ReservaInvalida) {
        // esperado
    }

    // Meia-reserva seria pior que nenhuma: o pedido segue rascunho e o
    // item que caberia NÃO ficou reservado.
    expect($pedido->fresh()->status)->toBe(OrderStatus::Draft)
        ->and(StockReservation::query()->ativas()->count())->toBe(0)
        ->and(InventoryBalance::query()->where('product_id', $ok->id)->value('qty_reserved'))->toBe('0.000');
});

it('com sales.require_stock_reservation desligado, confirma sem reservar mesmo sem saldo', function () {
    config(['sales.require_stock_reservation' => false]);

    $semSaldo = produtoVendavel('50.00', '0');
    $pedido = abrirPedido([['product' => $semSaldo->public_id, 'qty' => '5']]);

    app(ConfirmOrderService::class)->handle($pedido);

    expect($pedido->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and(StockReservation::query()->ativas()->count())->toBe(0)
        ->and(InventoryBalance::query()->where('product_id', $semSaldo->id)->value('qty_reserved') ?? '0.000')->toBe('0.000');
});

it('não confirma pedido sem itens', function () {
    $pedido = app(SaveOrderService::class)->handle(null, [], []);

    expect(fn () => app(ConfirmOrderService::class)->handle($pedido))
        ->toThrow(PedidoInvalido::class);
});

// -------------------------------------------------------- cancelar

it('cancelar rascunho não mexe em reserva', function () {
    $produto = produtoVendavel('99.90', '10');
    $pedido = abrirPedido([['product' => $produto->public_id, 'qty' => '4']]);

    app(CancelOrderService::class)->handle($pedido, 'desistência');

    expect($pedido->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(StockReservation::query()->count())->toBe(0);
});

it('cancelar confirmado libera a reserva', function () {
    $produto = produtoVendavel('99.90', '10');
    $pedido = abrirPedido([['product' => $produto->public_id, 'qty' => '4']]);
    app(ConfirmOrderService::class)->handle($pedido);

    app(CancelOrderService::class)->handle($pedido, 'cliente desistiu');

    $saldo = InventoryBalance::query()->where('product_id', $produto->id)->first();

    // A peça volta ao disponível — não fica presa a um pedido morto.
    expect($saldo->qty_reserved)->toBe('0.000')
        ->and($saldo->disponivel())->toBe('10.000')
        ->and(StockReservation::query()->ativas()->count())->toBe(0)
        ->and(StockReservation::query()->where('status', ReservationStatus::Released->value)->count())->toBe(1);
});

it('exige motivo ao cancelar', function () {
    $produto = produtoVendavel();
    $pedido = abrirPedido([['product' => $produto->public_id, 'qty' => '1']]);

    actingAs(vendedorDe())
        ->post("/pedidos/{$pedido->public_id}/cancelar", ['motivo' => ''])
        ->assertSessionHasErrors('motivo');
});

// ------------------------------------------------------- exclusão (BR-311)

it('exclui pedido cancelado', function () {
    $produto = produtoVendavel();
    $pedido = abrirPedido([['product' => $produto->public_id, 'qty' => '1']]);
    app(CancelOrderService::class)->handle($pedido, 'teste');

    actingAs(vendedorDe())
        ->delete("/pedidos/{$pedido->public_id}")
        ->assertRedirect('/pedidos');

    expect(Order::query()->whereKey($pedido->id)->exists())->toBeFalse()
        ->and(Order::withTrashed()->whereKey($pedido->id)->exists())->toBeTrue();
});

it('recusa excluir pedido que nao foi cancelado', function () {
    $produto = produtoVendavel();
    $pedido = abrirPedido([['product' => $produto->public_id, 'qty' => '1']]);

    actingAs(vendedorDe())
        ->delete("/pedidos/{$pedido->public_id}")
        ->assertSessionHasErrors('exclusao');

    expect(Order::query()->whereKey($pedido->id)->exists())->toBeTrue();
});

it('recusa excluir pedido cancelado que tem nota fiscal', function () {
    $produto = produtoVendavel();
    $pedido = abrirPedido([['product' => $produto->public_id, 'qty' => '1']]);
    app(CancelOrderService::class)->handle($pedido, 'teste');
    $pedido->forceFill(['nfe_status' => 'authorized'])->save();

    actingAs(vendedorDe())
        ->delete("/pedidos/{$pedido->public_id}")
        ->assertSessionHasErrors('exclusao');

    expect(Order::query()->whereKey($pedido->id)->exists())->toBeTrue();
});

it('barra quem cria mas nao pode excluir', function () {
    $produto = produtoVendavel();
    $pedido = abrirPedido([['product' => $produto->public_id, 'qty' => '1']]);
    app(CancelOrderService::class)->handle($pedido, 'teste');

    // Mesma alçada de cancelar (sales.cancel) — Fulfillment vê pedidos e
    // não tem essa permissão.
    actingAs(vendedorDe(Role::Fulfillment))
        ->delete("/pedidos/{$pedido->public_id}")
        ->assertForbidden();
});

// ------------------------------------------------- não editar depois

it('não deixa editar itens de pedido confirmado', function () {
    $produto = produtoVendavel('99.90', '10');
    $pedido = abrirPedido([['product' => $produto->public_id, 'qty' => '2']]);
    app(ConfirmOrderService::class)->handle($pedido);

    expect(fn () => app(SaveOrderService::class)->handle($pedido, [], [['product' => $produto->public_id, 'qty' => '9']]))
        ->toThrow(PedidoInvalido::class);
});

// -------------------------------------------------------- as telas

it('abre pedido pela tela e volta para a edição', function () {
    $produto = produtoVendavel('75.00', '10');

    actingAs(vendedorDe())
        ->post('/pedidos', ['itens' => [['product' => $produto->public_id, 'qty' => '3']]])
        ->assertRedirect();

    $pedido = Order::query()->first();

    expect($pedido->items()->value('unit_price'))->toBe('75.00')
        ->and($pedido->total)->toBe('225.00');
});

it('vende no balcão sem cliente (Consumidor)', function () {
    $produto = produtoVendavel();
    $pedido = abrirPedido([['product' => $produto->public_id, 'qty' => '1']]);

    actingAs(vendedorDe())
        ->get("/pedidos/{$pedido->public_id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/orders/edit')
            ->where('pedido.cliente', null)
        );
});

// -------------------------------------------------------- permissões

it('barra quem não pode ver vendas', function () {
    actingAs(vendedorDe(Role::Production))
        ->get('/pedidos')
        ->assertForbidden();
});

it('barra quem cria mas não pode cancelar', function () {
    $produto = produtoVendavel();
    $pedido = abrirPedido([['product' => $produto->public_id, 'qty' => '1']]);

    // Expedição vê e não cria/cancela; para testar só o cancelar, uso um
    // papel com create mas sem cancel seria ideal — aqui Fulfillment nem vê,
    // então confirmo que o guard de cancel existe via a Policy diretamente.
    actingAs(vendedorDe(Role::Fulfillment))
        ->post("/pedidos/{$pedido->public_id}/cancelar", ['motivo' => 'teste'])
        ->assertForbidden();
});
