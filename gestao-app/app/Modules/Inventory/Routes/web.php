<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockCountController;
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

    // Contagem física (BR-205). `{contagem}` resolve por `public_id`.
    Route::get('estoque/contagens', [StockCountController::class, 'index'])->name('inventory.counts.index');
    Route::post('estoque/contagens', [StockCountController::class, 'store'])->name('inventory.counts.store');
    Route::get('estoque/contagens/{contagem}', [StockCountController::class, 'show'])->name('inventory.counts.show');
    Route::post('estoque/contagens/{contagem}/contar', [StockCountController::class, 'count'])->name('inventory.counts.count');
    Route::post('estoque/contagens/{contagem}/encerrar', [StockCountController::class, 'close'])->name('inventory.counts.close');
    Route::post('estoque/contagens/{contagem}/aprovar', [StockCountController::class, 'approve'])->name('inventory.counts.approve');
    Route::post('estoque/contagens/{contagem}/cancelar', [StockCountController::class, 'cancel'])->name('inventory.counts.cancel');
});
