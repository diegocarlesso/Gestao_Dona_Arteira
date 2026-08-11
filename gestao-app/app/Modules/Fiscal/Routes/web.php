<?php

declare(strict_types=1);

use App\Modules\Fiscal\Http\Controllers\FiscalPanelController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas do módulo Fiscal
|--------------------------------------------------------------------------
|
| Mesma cadeia de middlewares dos demais módulos: conta.ativa →
| senha.trocada → 2fa.confirmado (ADR-0021). URLs em pt-BR.
|
*/

Route::middleware(['web', 'auth', 'conta.ativa', 'senha.trocada', '2fa.confirmado'])->group(function () {
    Route::get('fiscal', [FiscalPanelController::class, 'index'])->name('fiscal.panel');
    Route::post('fiscal/notas/{nota}/reprocessar', [FiscalPanelController::class, 'reprocessar'])->name('fiscal.reprocess');
});
