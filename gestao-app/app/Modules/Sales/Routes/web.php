<?php

declare(strict_types=1);

use App\Modules\Sales\Http\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas do módulo Vendas
|--------------------------------------------------------------------------
|
| Por ora só clientes: pedidos são Gate 02. Mesma cadeia de middlewares
| dos demais módulos (ADR-0021).
|
*/

Route::middleware(['web', 'auth', 'conta.ativa', 'senha.trocada', '2fa.confirmado'])->group(function () {
    Route::get('clientes', [CustomerController::class, 'index'])->name('sales.customers.index');
    Route::get('clientes/novo', [CustomerController::class, 'create'])->name('sales.customers.create');
    Route::post('clientes', [CustomerController::class, 'store'])->name('sales.customers.store');
    Route::get('clientes/{customer}', [CustomerController::class, 'edit'])->name('sales.customers.edit');
    Route::put('clientes/{customer}', [CustomerController::class, 'update'])->name('sales.customers.update');
    Route::delete('clientes/{customer}', [CustomerController::class, 'destroy'])->name('sales.customers.destroy');
});
