<?php

declare(strict_types=1);

use App\Modules\Finance\Http\Controllers\AgingReportController;
use App\Modules\Finance\Http\Controllers\BillingChargeController;
use App\Modules\Finance\Http\Controllers\CashFlowController;
use App\Modules\Finance\Http\Controllers\FinanceAccountController;
use App\Modules\Finance\Http\Controllers\FinanceCategoryController;
use App\Modules\Finance\Http\Controllers\TitleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas do módulo Financeiro
|--------------------------------------------------------------------------
|
| Mesma cadeia de middlewares dos demais módulos (ADR-0021). URLs em
| pt-BR, sob o prefixo `financeiro`.
|
*/

Route::middleware(['web', 'auth', 'conta.ativa', 'senha.trocada', '2fa.confirmado'])
    ->prefix('financeiro')
    ->name('finance.')
    ->group(function () {
        Route::get('/', [TitleController::class, 'index'])->name('titles.index');
        Route::post('titulos/{tipo}/{publicId}/baixa', [TitleController::class, 'settle'])->name('titles.settle');
        Route::post('baixas/{settlement:public_id}/estornar', [TitleController::class, 'reverse'])->name('settlements.reverse');

        Route::post('titulos/receivable/{publicId}/cobranca', [BillingChargeController::class, 'store'])->name('billing-charges.store');
        Route::post('cobrancas/{charge:public_id}/cancelar', [BillingChargeController::class, 'cancel'])->name('billing-charges.cancel');

        Route::get('contas', [FinanceAccountController::class, 'index'])->name('accounts.index');
        Route::post('contas', [FinanceAccountController::class, 'store'])->name('accounts.store');
        Route::put('contas/{account:public_id}', [FinanceAccountController::class, 'update'])->name('accounts.update');

        Route::get('categorias', [FinanceCategoryController::class, 'index'])->name('categories.index');
        Route::post('categorias', [FinanceCategoryController::class, 'store'])->name('categories.store');
        Route::put('categorias/{category:public_id}', [FinanceCategoryController::class, 'update'])->name('categories.update');

        Route::get('fluxo-de-caixa', [CashFlowController::class, 'index'])->name('cash-flow.index');
        Route::get('fluxo-de-caixa/csv', [CashFlowController::class, 'export'])->name('cash-flow.export');

        // Relatórios (pasta 20 §3.3). Permissão `reports.view`, família
        // própria — ver o operacional não garante ver o consolidado.
        // URL em português ("vencimentos"), nome interno da rota em
        // inglês por convenção do projeto (pasta 20 já faz o mesmo com
        // `catalog.reports.duplicate-names` → `/relatorios/produtos-repetidos`).
        Route::get('relatorios/vencimentos', [AgingReportController::class, 'index'])->name('reports.aging');
        Route::get('relatorios/vencimentos/csv', [AgingReportController::class, 'export'])->name('reports.aging.export');
    });
