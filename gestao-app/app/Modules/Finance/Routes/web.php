<?php

declare(strict_types=1);

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

        Route::get('contas', [FinanceAccountController::class, 'index'])->name('accounts.index');
        Route::post('contas', [FinanceAccountController::class, 'store'])->name('accounts.store');
        Route::put('contas/{account:public_id}', [FinanceAccountController::class, 'update'])->name('accounts.update');

        Route::get('categorias', [FinanceCategoryController::class, 'index'])->name('categories.index');
        Route::post('categorias', [FinanceCategoryController::class, 'store'])->name('categories.store');
        Route::put('categorias/{category:public_id}', [FinanceCategoryController::class, 'update'])->name('categories.update');

        Route::get('fluxo-de-caixa', [CashFlowController::class, 'index'])->name('cash-flow.index');
    });
