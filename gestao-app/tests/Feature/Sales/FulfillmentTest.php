<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\SetProductPriceService;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\RecordMovementService;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Events\OrderShipped;
use App\Modules\Sales\Exceptions\PedidoInvalido;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\CancelOrderService;
use App\Modules\Sales\Services\ConfirmOrderService;
use App\Modules\Sales\Services\FulfillmentService;
use App\Modules\Sales\Services\SaveOrderService;
use Database\Seeders\Identity\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Fulfillment — BR-303, docs/10-Vendas §5 (corte 3 do Gate 02)
|--------------------------------------------------------------------------
|
| O que o corte promete: confirmado → separando → embalado → expedido →
| entregue, com ações explícitas que só avançam da etapa certa. A
| expedição é a única que toca estoque — consome a reserva (sale_shipment
| baixa o on_hand) e dispara OrderShipped. Cancelar durante a separação
| ainda solta a reserva.
|
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Location::factory()->padrao()->create();
});

function expedidorDe(Role $papel = Role::Fulfillment): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum($papel);
}

function produtoComEstoque(string $preco = '100.00', string $estoque = '5'): Product
{
    $produto = Product::factory()->create();
    app(SetProductPriceService::class)->handle($produto, PriceList::Retail, $preco);

    if (bccomp($estoque, '0', 3) === 1) {
        $local = Location::query()->where('is_default', true)->firstOrFail();
        app(RecordMovementService::class)->handle($produto->id, $local->id, MovementType::ProductionOutput, $estoque);
    }

    return $produto;
}

/**
 * @param  list<array{product: string, qty: string}>|null  $itens
 */
function pedidoConfirmado(?array $itens = null): Order
{
    $itens ??= [['product' => produtoComEstoque()->public_id, 'qty' => '2']];

    $pedido = app(SaveOrderService::class)->handle(null, [], $itens);

    return app(ConfirmOrderService::class)->handle($pedido);
}

/** Leva um pedido confirmado até embalado, pronto para expedir. */
function pedidoEmbalado(?array $itens = null): Order
{
    $pedido = pedidoConfirmado($itens);
    $fulfillment = app(FulfillmentService::class);
    $fulfillment->iniciarSeparacao($pedido);

    return $fulfillment->embalar($pedido);
}

// -------------------------------------------------- avanço das etapas

it('avança confirmado → separando → embalado, sem tocar estoque', function () {
    $pedido = pedidoConfirmado();
    $fulfillment = app(FulfillmentService::class);

    $fulfillment->iniciarSeparacao($pedido);
    expect($pedido->fresh()->status)->toBe(OrderStatus::Separando);

    $fulfillment->embalar($pedido);
    expect($pedido->fresh()->status)->toBe(OrderStatus::Embalado);

    // A peça continua reservada — separar/embalar é organização, não saída.
    expect(StockReservation::query()->where('reference_id', $pedido->id)
        ->where('status', ReservationStatus::Active->value)->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('type', MovementType::SaleShipment->value)->exists())->toBeFalse();
});

it('registra cada transição no histórico, com autor', function () {
    $pedido = pedidoConfirmado();
    $expedidor = expedidorDe();

    app(FulfillmentService::class)->iniciarSeparacao($pedido, $expedidor->id);

    $ultima = $pedido->history()->orderByDesc('id')->first();
    expect($ultima->from_status)->toBe(OrderStatus::Confirmed)
        ->and($ultima->to_status)->toBe(OrderStatus::Separando)
        ->and($ultima->created_by)->toBe($expedidor->id);
});

// -------------------------------------------------- expedição

it('expedir consome a reserva: baixa on_hand e gera sale_shipment', function () {
    $produto = produtoComEstoque('100.00', '5');
    $pedido = pedidoEmbalado([['product' => $produto->public_id, 'qty' => '2']]);

    app(FulfillmentService::class)->expedir($pedido, 'BR123', 'Correios');

    $pedido->refresh();
    expect($pedido->status)->toBe(OrderStatus::Expedido)
        ->and($pedido->shipped_at)->not->toBeNull()
        ->and($pedido->tracking_code)->toBe('BR123')
        ->and($pedido->carrier)->toBe('Correios');

    // On_hand baixou de 5 para 3; reservado voltou a zero; a saída está no ledger.
    $saldo = InventoryBalance::query()->where('product_id', $produto->id)->firstOrFail();
    expect($saldo->qty_on_hand)->toBe('3.000')
        ->and($saldo->qty_reserved)->toBe('0.000');

    expect(InventoryMovement::query()
        ->where('product_id', $produto->id)
        ->where('type', MovementType::SaleShipment->value)
        ->where('reference_id', $pedido->id)
        ->exists())->toBeTrue();

    expect(StockReservation::query()->where('reference_id', $pedido->id)
        ->where('status', ReservationStatus::Consumed->value)->exists())->toBeTrue();
});

it('dispara OrderShipped ao expedir', function () {
    Event::fake([OrderShipped::class]);
    $pedido = pedidoEmbalado();

    app(FulfillmentService::class)->expedir($pedido);

    Event::assertDispatched(OrderShipped::class, fn (OrderShipped $e) => $e->pedido->is($pedido));
});

it('entregar fecha o ciclo sem mexer no estoque', function () {
    $produto = produtoComEstoque('100.00', '5');
    $pedido = pedidoEmbalado([['product' => $produto->public_id, 'qty' => '2']]);
    $fulfillment = app(FulfillmentService::class);

    $fulfillment->expedir($pedido);
    $movimentosAntes = InventoryMovement::query()->count();

    $fulfillment->entregar($pedido);

    expect($pedido->fresh()->status)->toBe(OrderStatus::Entregue)
        ->and($pedido->fresh()->delivered_at)->not->toBeNull()
        ->and(InventoryMovement::query()->count())->toBe($movimentosAntes);
});

// -------------------------------------------------- transições inválidas

it('recusa expedir um pedido que não está embalado', function () {
    $pedido = pedidoConfirmado(); // ainda Confirmado, não Embalado

    expect(fn () => app(FulfillmentService::class)->expedir($pedido))
        ->toThrow(PedidoInvalido::class);

    expect($pedido->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and(InventoryMovement::query()->where('type', MovementType::SaleShipment->value)->exists())->toBeFalse();
});

it('recusa separar um pedido ainda em rascunho', function () {
    $pedido = app(SaveOrderService::class)->handle(null, [], [
        ['product' => produtoComEstoque()->public_id, 'qty' => '1'],
    ]);

    expect(fn () => app(FulfillmentService::class)->iniciarSeparacao($pedido))
        ->toThrow(PedidoInvalido::class);
});

// -------------------------------------------------- cancelamento na separação

it('cancelar durante a separação libera a reserva', function () {
    $produto = produtoComEstoque('100.00', '5');
    $pedido = pedidoConfirmado([['product' => $produto->public_id, 'qty' => '2']]);
    app(FulfillmentService::class)->iniciarSeparacao($pedido);

    app(CancelOrderService::class)->handle($pedido, 'cliente desistiu');

    expect($pedido->fresh()->status)->toBe(OrderStatus::Cancelled);

    // Reserva liberada: disponível volta ao cheio, on_hand intacto (a peça não saiu).
    $saldo = InventoryBalance::query()->where('product_id', $produto->id)->firstOrFail();
    expect($saldo->qty_on_hand)->toBe('5.000')
        ->and($saldo->qty_reserved)->toBe('0.000');
});

// -------------------------------------------------- alçada (HTTP)

it('deixa a Expedição expedir pela rota', function () {
    $pedido = pedidoEmbalado();

    actingAs(expedidorDe())
        ->post("/pedidos/{$pedido->public_id}/expedir", ['tracking_code' => 'BR9', 'carrier' => 'Jadlog'])
        ->assertRedirect();

    expect($pedido->fresh()->status)->toBe(OrderStatus::Expedido);
});

it('nega expedir a quem não tem alçada de expedição', function () {
    // Finance não tem fulfillment.execute (Sales e Fulfillment têm).
    $pedido = pedidoEmbalado();

    actingAs(expedidorDe(Role::Finance))
        ->post("/pedidos/{$pedido->public_id}/expedir")
        ->assertForbidden();

    expect($pedido->fresh()->status)->toBe(OrderStatus::Embalado);
});
