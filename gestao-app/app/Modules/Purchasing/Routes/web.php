<?php

declare(strict_types=1);

use App\Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas do módulo Compras
|--------------------------------------------------------------------------
|
| Fase 1 (nota de escopo da pasta 11): fornecedores e pedido de compra até
| a confirmação, que gera a conta a pagar. Recebimento físico e quarentena
| de secagem são a Fase 2 e não têm rota aqui. Mesma cadeia de middlewares
| dos demais módulos (ADR-0021).
|
*/

Route::middleware(['web', 'auth', 'conta.ativa', 'senha.trocada', '2fa.confirmado'])->group(function () {
    // Fornecedores. `{supplier}` resolve por `public_id`.
    Route::get('fornecedores', [SupplierController::class, 'index'])->name('purchasing.suppliers.index');
    Route::get('fornecedores/novo', [SupplierController::class, 'create'])->name('purchasing.suppliers.create');
    Route::get('fornecedores/buscar', [SupplierController::class, 'search'])->name('purchasing.suppliers.search');
    Route::post('fornecedores', [SupplierController::class, 'store'])->name('purchasing.suppliers.store');
    Route::get('fornecedores/{supplier}', [SupplierController::class, 'edit'])->name('purchasing.suppliers.edit');
    Route::put('fornecedores/{supplier}', [SupplierController::class, 'update'])->name('purchasing.suppliers.update');
    Route::delete('fornecedores/{supplier}', [SupplierController::class, 'destroy'])->name('purchasing.suppliers.destroy');

    // Pedidos de compra (BR-402). `{purchase_order}` resolve por `public_id`.
    Route::get('compras', [PurchaseOrderController::class, 'index'])->name('purchasing.purchase_orders.index');
    Route::get('compras/novo', [PurchaseOrderController::class, 'create'])->name('purchasing.purchase_orders.create');
    Route::get('compras/buscar-produtos', [PurchaseOrderController::class, 'searchProducts'])->name('purchasing.purchase_orders.search-products');
    Route::post('compras', [PurchaseOrderController::class, 'store'])->name('purchasing.purchase_orders.store');
    Route::get('compras/{purchase_order}', [PurchaseOrderController::class, 'edit'])->name('purchasing.purchase_orders.edit');
    Route::put('compras/{purchase_order}', [PurchaseOrderController::class, 'update'])->name('purchasing.purchase_orders.update');

    // Transições da máquina de estados (cada uma grava a trilha).
    Route::post('compras/{purchase_order}/enviar', [PurchaseOrderController::class, 'send'])->name('purchasing.purchase_orders.send');
    Route::post('compras/{purchase_order}/confirmar', [PurchaseOrderController::class, 'confirm'])->name('purchasing.purchase_orders.confirm');
    Route::post('compras/{purchase_order}/cancelar', [PurchaseOrderController::class, 'cancel'])->name('purchasing.purchase_orders.cancel');
});
