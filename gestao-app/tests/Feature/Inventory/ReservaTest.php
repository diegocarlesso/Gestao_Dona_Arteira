<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Exceptions\ReservaInvalida;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\RecordMovementService;
use App\Modules\Inventory\Services\ReserveStockService;

/*
|--------------------------------------------------------------------------
| Reserva de estoque — BR-203/BR-204, docs/09-Estoque §4
|--------------------------------------------------------------------------
|
| O antídoto do oversell: reserva-se o disponível (on_hand − reserved),
| nunca além. Confirmar pedido reserva, cancelar libera, expedir consome —
| e o `qty_reserved` do saldo tem de refletir a soma das reservas ativas.
|
*/

function comSaldo(string $qty): array
{
    $produto = Product::factory()->create();
    $local = Location::factory()->padrao()->create();
    app(RecordMovementService::class)->handle($produto->id, $local->id, MovementType::ProductionOutput, $qty);

    return [$produto, $local];
}

function reserva(): ReserveStockService
{
    return app(ReserveStockService::class);
}

// ------------------------------------------------------- reservar

it('reserva reduz o disponível sem tocar no físico', function () {
    [$p, $l] = comSaldo('10');

    reserva()->reservar($p->id, $l->id, '4');

    $saldo = InventoryBalance::query()->where('product_id', $p->id)->first();

    // Físico intacto (a peça não saiu), disponível caiu.
    expect($saldo->qty_on_hand)->toBe('10.000')
        ->and($saldo->qty_reserved)->toBe('4.000')
        ->and($saldo->disponivel())->toBe('6.000');
});

it('recusa reservar além do disponível — o oversell', function () {
    [$p, $l] = comSaldo('3');

    expect(fn () => reserva()->reservar($p->id, $l->id, '4'))
        ->toThrow(ReservaInvalida::class);
});

it('conta o já reservado ao medir o disponível', function () {
    [$p, $l] = comSaldo('10');

    reserva()->reservar($p->id, $l->id, '7');

    // Sobram 3 disponíveis; pedir 4 tem de falhar mesmo com 10 físicos.
    expect(fn () => reserva()->reservar($p->id, $l->id, '4'))
        ->toThrow(ReservaInvalida::class);

    reserva()->reservar($p->id, $l->id, '3');

    expect(InventoryBalance::query()->where('product_id', $p->id)->value('qty_reserved'))->toBe('10.000');
});

it('não gera movimento no ledger ao reservar — a peça não saiu', function () {
    [$p, $l] = comSaldo('10');

    reserva()->reservar($p->id, $l->id, '4');

    // Só o movimento que criou o saldo; a reserva não é movimento.
    expect(InventoryMovement::query()->where('product_id', $p->id)->count())->toBe(1);
});

// ------------------------------------------------------- liberar

it('liberar devolve ao disponível e não mexe no físico', function () {
    [$p, $l] = comSaldo('10');
    $r = reserva()->reservar($p->id, $l->id, '4');

    reserva()->liberar($r);

    $saldo = InventoryBalance::query()->where('product_id', $p->id)->first();

    expect($saldo->qty_on_hand)->toBe('10.000')
        ->and($saldo->qty_reserved)->toBe('0.000')
        ->and($r->fresh()->status)->toBe(ReservationStatus::Released);
});

// ------------------------------------------------------- consumir

it('consumir baixa o físico pelo ledger e zera a reserva', function () {
    [$p, $l] = comSaldo('10');
    $r = reserva()->reservar($p->id, $l->id, '4');

    reserva()->consumir($r);

    $saldo = InventoryBalance::query()->where('product_id', $p->id)->first();

    // Físico caiu (expedição), reservado voltou a zero, e há o
    // sale_shipment no ledger referenciando a origem.
    expect($saldo->qty_on_hand)->toBe('6.000')
        ->and($saldo->qty_reserved)->toBe('0.000')
        ->and($r->fresh()->status)->toBe(ReservationStatus::Consumed)
        ->and(InventoryMovement::query()->where('type', MovementType::SaleShipment->value)->exists())->toBeTrue();
});

it('o disponível não muda entre reservar e consumir', function () {
    [$p, $l] = comSaldo('10');
    $r = reserva()->reservar($p->id, $l->id, '4');

    $antes = InventoryBalance::query()->where('product_id', $p->id)->first()->disponivel();
    reserva()->consumir($r);
    $depois = InventoryBalance::query()->where('product_id', $p->id)->first()->disponivel();

    // Reservar já descontou o disponível; consumir baixa on_hand e reserved
    // juntos, então o disponível fica igual — a conta que a venda espera.
    expect($antes)->toBe('6.000')->and($depois)->toBe('6.000');
});

// ------------------------------------------------- reserva encerrada

it('não deixa liberar nem consumir duas vezes', function () {
    [$p, $l] = comSaldo('10');
    $r = reserva()->reservar($p->id, $l->id, '4');
    reserva()->liberar($r);

    expect(fn () => reserva()->liberar($r->fresh()))->toThrow(ReservaInvalida::class)
        ->and(fn () => reserva()->consumir($r->fresh()))->toThrow(ReservaInvalida::class);
});

it('guarda a origem da reserva para liberar/consumir por pedido', function () {
    [$p, $l] = comSaldo('10');

    $r = reserva()->reservar($p->id, $l->id, '4', 'order', 42);

    expect(StockReservation::query()->ativas()->where('reference_type', 'order')->where('reference_id', 42)->exists())
        ->toBeTrue();
});
