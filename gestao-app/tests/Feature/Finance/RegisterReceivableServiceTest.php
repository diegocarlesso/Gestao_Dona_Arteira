<?php

declare(strict_types=1);

use App\Modules\Finance\Enums\FinanceCategoryType;
use App\Modules\Finance\Exceptions\TituloInvalido;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\RegisterReceivableService;
use Database\Seeders\Finance\FinanceCategorySeeder;

/*
|--------------------------------------------------------------------------
| Títulos a receber — BR-501/BR-503, docs/12-Financeiro §3
|--------------------------------------------------------------------------
|
| Espelho de RegisterPayableServiceTest — mesmas garantias, do outro lado
| do caixa: categoria obrigatória e do tipo certo, valor positivo e sem
| float, origem por par tipo/id sem FK cross-módulo.
|
*/

function registrarRecebivel(
    string $originType = 'order',
    int $originId = 42,
    string $customerName = 'Maria da Silva',
    string $amount = '250.00',
    ?string $dueDate = null,
    ?int $categoryId = null,
): Receivable {
    return app(RegisterReceivableService::class)->registrar(
        originType: $originType,
        originId: $originId,
        customerName: $customerName,
        amount: $amount,
        dueDate: $dueDate,
        categoryId: $categoryId,
    );
}

it('usa a categoria padrão de vendas quando nenhuma é informada (BR-503)', function () {
    $this->seed(FinanceCategorySeeder::class);

    $titulo = registrarRecebivel();

    expect($titulo->category->name)->toBe('Vendas')
        ->and($titulo->category->type)->toBe(FinanceCategoryType::Receivable);
});

it('recusa registrar sem categoria padrão semeada', function () {
    expect(fn () => registrarRecebivel())
        ->toThrow(TituloInvalido::class, 'Vendas');

    expect(Receivable::query()->count())->toBe(0);
});

it('recusa classificar um a receber numa categoria de despesa', function () {
    $categoria = FinanceCategory::factory()->create(['name' => 'Marketing']);

    expect(fn () => registrarRecebivel(categoryId: $categoria->id))
        ->toThrow(TituloInvalido::class);
});

it('recusa título com valor zero ou negativo', function (string $valor) {
    $this->seed(FinanceCategorySeeder::class);

    expect(fn () => registrarRecebivel(amount: $valor))
        ->toThrow(TituloInvalido::class);
})->with(['0.00', '-10.00']);

it('guarda o nome do cliente como snapshot e nasce aberto', function () {
    $this->seed(FinanceCategorySeeder::class);

    $titulo = registrarRecebivel(customerName: 'Maria da Silva');

    expect($titulo->customer_name)->toBe('Maria da Silva')
        ->and($titulo->status)->toBe(Receivable::STATUS_OPEN)
        ->and($titulo->aberto())->toBeTrue();
});

it('grava a origem como par tipo/id, sem FK cross-módulo', function () {
    $this->seed(FinanceCategorySeeder::class);

    registrarRecebivel(originType: 'order', originId: 77);

    expect(Receivable::query()->daOrigem('order', 77)->count())->toBe(1)
        ->and(Receivable::query()->daOrigem('order', 78)->count())->toBe(0);
});
