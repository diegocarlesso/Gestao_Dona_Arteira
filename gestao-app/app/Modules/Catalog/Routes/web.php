<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\DuplicateNamesReportController;
use App\Modules\Catalog\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas do módulo Catálogo
|--------------------------------------------------------------------------
|
| Carregadas pelo CatalogServiceProvider. Cada módulo traz as suas
| (pasta 05 §2), em vez de tudo inchar routes/web.php.
|
| URLs em pt-BR, caminhos de arquivo em inglês — convenção do projeto.
| A cadeia de middlewares é a mesma do Identity: conta.ativa →
| senha.trocada → 2fa.confirmado (ADR-0021).
|
*/

Route::middleware(['web', 'auth', 'conta.ativa', 'senha.trocada', '2fa.confirmado'])->group(function () {
    Route::get('produtos', [ProductController::class, 'index'])->name('catalog.products.index');
    Route::get('produtos/novo', [ProductController::class, 'create'])->name('catalog.products.create');
    Route::post('produtos', [ProductController::class, 'store'])->name('catalog.products.store');

    // {product} resolve por `public_id` — o SKU é código de negócio e
    // aparece na tela, mas não em URL (convenção da pasta 04).
    Route::get('produtos/{product}', [ProductController::class, 'edit'])->name('catalog.products.edit');
    Route::put('produtos/{product}', [ProductController::class, 'update'])->name('catalog.products.update');

    Route::post('produtos/{product}/preco', [ProductController::class, 'setPrice'])->name('catalog.products.price');
    Route::post('produtos/{product}/arquivar', [ProductController::class, 'archive'])->name('catalog.products.archive');

    // Relatórios do catálogo (pasta 20). Permissão `reports.view`, que é
    // família própria na matriz da pasta 19 — não é `catalog.view`.
    Route::get('relatorios/produtos-repetidos', [DuplicateNamesReportController::class, 'index'])
        ->name('catalog.reports.duplicate-names');
    Route::get('relatorios/produtos-repetidos/csv', [DuplicateNamesReportController::class, 'export'])
        ->name('catalog.reports.duplicate-names.export');
});
