<?php

declare(strict_types=1);

namespace App\Modules\Sales\Providers;

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Policies\CustomerPolicy;
use App\Modules\Sales\Policies\OrderPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Provider do módulo Vendas.
 *
 * Nasce com clientes só. Pedidos, máquina de estados e expedição são o
 * Gate 02 — e ficam aqui, como a pasta 05 §2 já previa, em vez de virarem
 * um módulo "Customers" que depois teria de ser fundido.
 */
class SalesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
    }
}
