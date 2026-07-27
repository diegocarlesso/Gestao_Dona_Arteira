<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Provider do módulo Estoque.
 *
 * Sem rotas ainda: esta fatia entrega o ledger e a via de escrita, que é
 * o que Produção, Vendas e Compras vão consumir. As telas — extrato,
 * posição e contagem — vêm na fatia seguinte.
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void {}
}
