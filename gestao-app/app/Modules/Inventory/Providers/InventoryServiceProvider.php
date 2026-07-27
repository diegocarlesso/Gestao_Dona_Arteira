<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Policies\InventoryPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Provider do módulo Estoque.
 *
 * Cada módulo registra o que é seu (pasta 05 §2, ADR-0020).
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        Gate::policy(InventoryBalance::class, InventoryPolicy::class);
    }
}
