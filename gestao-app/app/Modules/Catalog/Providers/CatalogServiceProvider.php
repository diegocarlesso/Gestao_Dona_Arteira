<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Policies\ProductPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Provider do módulo Catálogo.
 *
 * Cada módulo registra o que é seu (pasta 05 §2, ADR-0020), sem pacote de
 * "module system": PSR-4 + Service Provider próprio.
 */
class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        Gate::policy(Product::class, ProductPolicy::class);
    }
}
