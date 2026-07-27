<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas do módulo Estoque
|--------------------------------------------------------------------------
|
| Mesma cadeia de middlewares dos demais módulos: conta.ativa →
| senha.trocada → 2fa.confirmado (ADR-0021). URLs em pt-BR.
|
*/

Route::middleware(['web', 'auth', 'conta.ativa', 'senha.trocada', '2fa.confirmado'])->group(function () {
    Route::get('estoque', [StockController::class, 'index'])->name('inventory.position');

    // `{produto}` chega como string e é resolvido pelo Service do
    // Catálogo — este módulo não pode type-hintar `Product` para deixar o
    // Laravel resolver por model binding (ADR-0020). O parâmetro é o
    // `public_id`, nunca o id interno nem o SKU (convenção da pasta 04).
    Route::get('estoque/produtos/{produto}/extrato', [StockController::class, 'statement'])
        ->name('inventory.statement');
});
