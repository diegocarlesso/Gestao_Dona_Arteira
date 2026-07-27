<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Events\StockMovementRecorded;
use App\Modules\Inventory\Exceptions\MovimentoInvalido;
use App\Modules\Inventory\Exceptions\SaldoInsuficiente;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Services\RecordMovementService;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Ledger de estoque — docs/09-Estoque, ADR-0008
|--------------------------------------------------------------------------
|
| As invariantes que o módulo promete: movimento é imutável e o saldo é a
| soma dele (BR-202); saldo nunca fica negativo (BR-201); o sinal vem do
| tipo, não de quem chama; custo médio só muda por entrada com custo
| (BR-206).
|
*/

function local(): Location
{
    return Location::factory()->padrao()->create();
}

function produto(): Product
{
    return Product::factory()->create();
}

function mover(Product $p, Location $l, MovementType $tipo, string $qty, ...$extra): InventoryMovement
{
    return app(RecordMovementService::class)->handle($p->id, $l->id, $tipo, $qty, ...$extra);
}

// ------------------------------------------------ o sinal vem do tipo

it('grava entrada positiva e saída negativa a partir do tipo', function () {
    [$p, $l] = [produto(), local()];

    $entrada = mover($p, $l, MovementType::ProductionOutput, '10');
    $saida = mover($p, $l, MovementType::SaleShipment, '4');

    // Quem chama passa sempre positivo; inverter o sinal por engano
    // registraria uma venda que aumenta o estoque.
    expect($entrada->qty)->toBe('10.000')
        ->and($saida->qty)->toBe('-4.000');
});

it('recusa quantidade zero ou negativa', function () {
    [$p, $l] = [produto(), local()];

    expect(fn () => mover($p, $l, MovementType::ProductionOutput, '0'))
        ->toThrow(MovimentoInvalido::class);

    // Negativo teria dois significados possíveis — e um deles inverteria
    // o sinal que o tipo já definiu.
    expect(fn () => mover($p, $l, MovementType::SaleShipment, '-5'))
        ->toThrow(MovimentoInvalido::class);
});

// -------------------------------------------------------- o saldo

it('materializa o saldo somando os movimentos', function () {
    [$p, $l] = [produto(), local()];

    mover($p, $l, MovementType::ProductionOutput, '10');
    mover($p, $l, MovementType::ProductionOutput, '5.500');
    mover($p, $l, MovementType::SaleShipment, '3');

    $saldo = InventoryBalance::query()->where('product_id', $p->id)->first();

    expect($saldo->qty_on_hand)->toBe('12.500');
});

it('mantem o saldo igual a soma do ledger — a conta que a reconciliacao confere', function () {
    [$p, $l] = [produto(), local()];

    mover($p, $l, MovementType::PurchaseReceipt, '40', '3.00');
    mover($p, $l, MovementType::SaleShipment, '7');
    mover($p, $l, MovementType::Loss, '2', null, 'quebrou no transporte');

    $soma = InventoryMovement::query()->where('product_id', $p->id)->sum('qty');
    $saldo = InventoryBalance::query()->where('product_id', $p->id)->value('qty_on_hand');

    expect((float) $saldo)->toBe((float) $soma);
});

it('abre saldo zerado na primeira movimentacao do produto', function () {
    [$p, $l] = [produto(), local()];

    expect(InventoryBalance::query()->count())->toBe(0);

    mover($p, $l, MovementType::AdjustmentIn, '3', null, 'contagem inicial');

    expect(InventoryBalance::query()->count())->toBe(1);
});

it('separa saldo por local', function () {
    $p = produto();
    $atelie = local();
    $deposito = Location::factory()->create();

    mover($p, $atelie, MovementType::ProductionOutput, '10');
    mover($p, $deposito, MovementType::ProductionOutput, '4');

    expect(InventoryBalance::query()->where('location_id', $atelie->id)->value('qty_on_hand'))->toBe('10.000')
        ->and(InventoryBalance::query()->where('location_id', $deposito->id)->value('qty_on_hand'))->toBe('4.000');
});

// ---------------------------------------------------------- BR-201

it('recusa o movimento que deixaria o saldo negativo', function () {
    [$p, $l] = [produto(), local()];

    mover($p, $l, MovementType::ProductionOutput, '3');

    expect(fn () => mover($p, $l, MovementType::SaleShipment, '4'))
        ->toThrow(SaldoInsuficiente::class);
});

it('nao grava movimento nenhum quando o saldo seria negativo', function () {
    [$p, $l] = [produto(), local()];

    try {
        mover($p, $l, MovementType::SaleShipment, '1');
    } catch (SaldoInsuficiente) {
        // esperado
    }

    // A transação volta inteira — inclusive a linha de saldo que o
    // serviço abre antes de travar. Movimento recusado não deixa rastro
    // nenhum, e ledger com movimento que o saldo não reflete é
    // exatamente a divergência que o módulo existe para impossibilitar.
    expect(InventoryMovement::query()->count())->toBe(0)
        ->and(InventoryBalance::query()->count())->toBe(0);
});

it('recusa saldo negativo tambem no ajuste', function () {
    [$p, $l] = [produto(), local()];

    // A BR-201 abre exceção para "ajuste com permissão específica", e a
    // permissão decide quem ajusta — não autoriza prateleira com menos de
    // zero peça. Ver `SaldoInsuficiente`.
    expect(fn () => mover($p, $l, MovementType::AdjustmentOut, '1', null, 'sumiu'))
        ->toThrow(SaldoInsuficiente::class);
});

// ---------------------------------------------------------- BR-205

it('exige motivo no ajuste e na perda', function () {
    [$p, $l] = [produto(), local()];

    expect(fn () => mover($p, $l, MovementType::AdjustmentIn, '5'))
        ->toThrow(MovimentoInvalido::class);

    mover($p, $l, MovementType::ProductionOutput, '5');

    expect(fn () => mover($p, $l, MovementType::Loss, '1'))
        ->toThrow(MovimentoInvalido::class);
});

it('nao exige motivo nos movimentos que ja tem origem', function () {
    [$p, $l] = [produto(), local()];

    // Uma expedição se explica pelo pedido que a originou; obrigar a
    // digitar motivo em cada uma treinaria a equipe a escrever "venda".
    mover($p, $l, MovementType::ProductionOutput, '5');
    mover($p, $l, MovementType::SaleShipment, '2');

    expect(InventoryMovement::query()->count())->toBe(2);
});

// ---------------------------------------------------------- BR-202

it('recusa alterar ou apagar movimento gravado', function () {
    [$p, $l] = [produto(), local()];

    $movimento = mover($p, $l, MovementType::ProductionOutput, '10');

    $movimento->qty = '999';

    expect($movimento->save())->toBeFalse()
        ->and($movimento->delete())->toBeFalse()
        ->and(InventoryMovement::query()->value('qty'))->toBe('10.000');
});

it('estorna por contra-movimento, preservando o original', function () {
    [$p, $l] = [produto(), local()];

    $original = mover($p, $l, MovementType::ProductionOutput, '10');

    mover($p, $l, MovementType::AdjustmentOut, '10', null, 'estorno de lançamento errado', 'inventory_movement', $original->id);

    expect(InventoryMovement::query()->count())->toBe(2)
        ->and(InventoryBalance::query()->value('qty_on_hand'))->toBe('0.000')
        ->and(InventoryMovement::query()->where('reference_id', $original->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------- BR-206

it('recalcula o custo medio a cada entrada com custo', function () {
    [$p, $l] = [produto(), local()];

    mover($p, $l, MovementType::PurchaseReceipt, '10', '3.00');
    mover($p, $l, MovementType::PurchaseReceipt, '10', '5.00');

    // (10×3 + 10×5) / 20 = 4,00
    expect(InventoryBalance::query()->value('avg_cost'))->toBe('4.00');
});

it('nao deixa a saida mexer no custo medio', function () {
    [$p, $l] = [produto(), local()];

    mover($p, $l, MovementType::PurchaseReceipt, '10', '3.00');
    mover($p, $l, MovementType::SaleShipment, '4');

    expect(InventoryBalance::query()->value('avg_cost'))->toBe('3.00');
});

it('nao deixa a contagem zerar o custo medio', function () {
    [$p, $l] = [produto(), local()];

    mover($p, $l, MovementType::PurchaseReceipt, '10', '3.00');
    mover($p, $l, MovementType::AdjustmentIn, '2', null, 'peça reapareceu na prateleira');

    // Contagem corrige quantidade, não valor. Sem esta separação, a peça
    // reaparecida valeria zero e derrubaria a margem de todas as outras.
    expect(InventoryBalance::query()->value('avg_cost'))->toBe('3.00')
        ->and(InventoryBalance::query()->value('qty_on_hand'))->toBe('12.000');
});

// ---------------------------------------------------------- evento

it('anuncia o movimento para quem precisa reagir', function () {
    Event::fake([StockMovementRecorded::class]);

    [$p, $l] = [produto(), local()];
    mover($p, $l, MovementType::ProductionOutput, '10');

    // É por aqui que a publicação no site (BR-204) e o alerta de mínimo
    // vão ouvir, sem que o Estoque conheça nenhum dos dois.
    Event::assertDispatched(StockMovementRecorded::class);
});

// ---------------------------------------------------------- o local

it('mantem um unico local padrao', function () {
    $primeiro = local();
    $segundo = Location::factory()->padrao()->create();

    expect($primeiro->fresh()->is_default)->toBeFalse()
        ->and($segundo->fresh()->is_default)->toBeTrue()
        ->and(Location::padrao()->id)->toBe($segundo->id);
});
