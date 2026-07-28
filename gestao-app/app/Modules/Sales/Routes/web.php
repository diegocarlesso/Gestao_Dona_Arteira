<?php

declare(strict_types=1);

use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\OrderController;
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

    // Pedidos (BR-303). `{order}` resolve por `public_id`.
    Route::get('pedidos', [OrderController::class, 'index'])->name('sales.orders.index');
    Route::get('pedidos/buscar-produtos', [OrderController::class, 'searchProducts'])->name('sales.orders.search-products');
    Route::get('pedidos/buscar-clientes', [OrderController::class, 'searchCustomers'])->name('sales.orders.search-customers');
    Route::post('pedidos', [OrderController::class, 'store'])->name('sales.orders.store');
    Route::get('pedidos/{order}', [OrderController::class, 'edit'])->name('sales.orders.edit');
    Route::put('pedidos/{order}', [OrderController::class, 'update'])->name('sales.orders.update');
    Route::post('pedidos/{order}/confirmar', [OrderController::class, 'confirm'])->name('sales.orders.confirm');
    Route::post('pedidos/{order}/cancelar', [OrderController::class, 'cancel'])->name('sales.orders.cancel');
});
