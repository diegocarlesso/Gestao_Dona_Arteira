<?php

declare(strict_types=1);

use App\Modules\Finance\Exceptions\TituloInvalido;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\RegisterSettlementService;

/*
|--------------------------------------------------------------------------
| Baixa de título — BR-502/BR-509, docs/12-Financeiro §3
|--------------------------------------------------------------------------
|
| O que promete: baixa total fecha o título; baixa parcial mantém o saldo
| aberto sem fingir que fechou; baixa que excede o saldo é recusada (nunca
| vira baixa total silenciosa, BR-509); serve pagável e recebível pelo
| mesmo Service, sem duplicar a matemática.
|
*/

it('baixa total fecha o título recebível', function () {
    $titulo = Receivable::factory()->create(['amount' => '250.00']);
    $conta = FinanceAccount::factory()->create();

    app(RegisterSettlementService::class)->handle(
        titleType: 'receivable',
        titleId: $titulo->id,
        accountId: $conta->id,
        amount: '250.00',
        settledAt: now()->toDateString(),
    );

    expect($titulo->fresh()->status)->toBe(Receivable::STATUS_SETTLED)
        ->and($titulo->fresh()->aberto())->toBeFalse()
        ->and($titulo->fresh()->saldoAberto())->toBe('0.00');
});

it('baixa parcial mantém o saldo aberto, sem fechar o título', function () {
    $titulo = Payable::factory()->create(['amount' => '250.00']);
    $conta = FinanceAccount::factory()->create();

    app(RegisterSettlementService::class)->handle(
        titleType: 'payable',
        titleId: $titulo->id,
        accountId: $conta->id,
        amount: '100.00',
        settledAt: now()->toDateString(),
    );

    expect($titulo->fresh()->status)->toBe(Payable::STATUS_PARTIALLY_SETTLED)
        ->and($titulo->fresh()->saldoAberto())->toBe('150.00');
});

it('duas baixas parciais que somam o valor total fecham o título', function () {
    $titulo = Payable::factory()->create(['amount' => '250.00']);
    $conta = FinanceAccount::factory()->create();
    $service = app(RegisterSettlementService::class);

    $service->handle('payable', $titulo->id, $conta->id, '100.00', now()->toDateString());
    $service->handle('payable', $titulo->id, $conta->id, '150.00', now()->toDateString());

    expect($titulo->fresh()->status)->toBe(Payable::STATUS_SETTLED);
});

it('recusa baixa que excede o saldo aberto (BR-509)', function () {
    $titulo = Receivable::factory()->create(['amount' => '250.00']);
    $conta = FinanceAccount::factory()->create();

    expect(fn () => app(RegisterSettlementService::class)->handle(
        titleType: 'receivable',
        titleId: $titulo->id,
        accountId: $conta->id,
        amount: '250.01',
        settledAt: now()->toDateString(),
    ))->toThrow(TituloInvalido::class);

    expect($titulo->fresh()->status)->toBe(Receivable::STATUS_OPEN);
});

it('recusa dar baixa em conta inexistente', function () {
    $titulo = Payable::factory()->create();

    expect(fn () => app(RegisterSettlementService::class)->handle(
        titleType: 'payable',
        titleId: $titulo->id,
        accountId: 999_999,
        amount: '10.00',
        settledAt: now()->toDateString(),
    ))->toThrow(TituloInvalido::class);
});

it('recusa tipo de título desconhecido', function () {
    $conta = FinanceAccount::factory()->create();

    expect(fn () => app(RegisterSettlementService::class)->handle(
        titleType: 'invoice',
        titleId: 1,
        accountId: $conta->id,
        amount: '10.00',
        settledAt: now()->toDateString(),
    ))->toThrow(TituloInvalido::class);
});

it('o saldo da conta soma as baixas', function () {
    $titulo1 = Payable::factory()->create(['amount' => '100.00']);
    $titulo2 = Receivable::factory()->create(['amount' => '300.00']);
    $conta = FinanceAccount::factory()->create();
    $service = app(RegisterSettlementService::class);

    $service->handle('payable', $titulo1->id, $conta->id, '100.00', now()->toDateString());
    $service->handle('receivable', $titulo2->id, $conta->id, '300.00', now()->toDateString());

    expect($conta->saldo())->toBe('400.00');
});
