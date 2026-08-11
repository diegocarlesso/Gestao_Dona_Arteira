<?php

declare(strict_types=1);

use App\Modules\Finance\Exceptions\TituloInvalido;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\RegisterSettlementService;
use App\Modules\Finance\Services\ReverseSettlementService;

/*
|--------------------------------------------------------------------------
| Estorno de baixa — BR-504, docs/12-Financeiro §3
|--------------------------------------------------------------------------
|
| Nunca DELETE: o estorno é uma linha nova, negativa, que devolve o título
| ao estado real — e a baixa original continua na trilha para sempre.
|
*/

it('estorna uma baixa total: o título volta a ficar aberto, sem apagar a baixa original', function () {
    $titulo = Receivable::factory()->create(['amount' => '250.00']);
    $conta = FinanceAccount::factory()->create();

    $baixa = app(RegisterSettlementService::class)->handle(
        titleType: 'receivable',
        titleId: $titulo->id,
        accountId: $conta->id,
        amount: '250.00',
        settledAt: now()->toDateString(),
    );

    expect($titulo->fresh()->status)->toBe(Receivable::STATUS_SETTLED);

    $estorno = app(ReverseSettlementService::class)->handle($baixa->id, 'PIX caiu em duplicidade');

    expect($titulo->fresh()->status)->toBe(Receivable::STATUS_OPEN)
        ->and($titulo->fresh()->saldoAberto())->toBe('250.00')
        ->and($estorno->amount)->toBe('-250.00')
        ->and($estorno->reversal_of_id)->toBe($baixa->id)
        ->and($estorno->estorno())->toBeTrue()
        // A baixa original continua existindo — nunca DELETE.
        ->and(FinanceSettlement::query()->find($baixa->id))->not->toBeNull();
});

it('recusa estornar um estorno', function () {
    $titulo = Receivable::factory()->create(['amount' => '250.00']);
    $conta = FinanceAccount::factory()->create();

    $baixa = app(RegisterSettlementService::class)->handle(
        'receivable', $titulo->id, $conta->id, '250.00', now()->toDateString(),
    );
    $estorno = app(ReverseSettlementService::class)->handle($baixa->id, 'motivo qualquer');

    expect(fn () => app(ReverseSettlementService::class)->handle($estorno->id, 'segundo estorno'))
        ->toThrow(TituloInvalido::class);
});

it('recusa estornar uma baixa inexistente', function () {
    expect(fn () => app(ReverseSettlementService::class)->handle(999_999, 'motivo'))
        ->toThrow(TituloInvalido::class);
});
