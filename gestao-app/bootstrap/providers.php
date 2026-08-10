<?php

declare(strict_types=1);

use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Finance\Providers\FinanceServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Integrations\Providers\IntegrationsServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Sales\Providers\SalesServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,

    // Um provider por módulo (pasta 05 §2). A ordem aqui é a ordem de
    // boot: Identity vem primeiro porque define o Gate que os demais
    // módulos consultam.
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    // Estoque depois do Catálogo: o movimento aponta para produto, e não
    // o contrário (pasta 09 §7).
    InventoryServiceProvider::class,
    // Financeiro antes de Vendas e Compras: os dois geram título por
    // dentro do Service dele, nunca o contrário.
    FinanceServiceProvider::class,
    SalesServiceProvider::class,
    IntegrationsServiceProvider::class,
];
