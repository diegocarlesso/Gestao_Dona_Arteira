<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Providers;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Policies\PurchaseOrderPolicy;
use App\Modules\Purchasing\Policies\SupplierPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Provider do módulo Compras.
 *
 * Nasce com fornecedores e pedido de compra (Fase 1 da pasta 11). O
 * recebimento com conferência e a quarentena de secagem (ADR-0024) entram
 * aqui na Fase 2, junto do Estoque — e ficam neste módulo, não num
 * "Recebimento" à parte que depois teria de ser fundido.
 */
class PurchasingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
    }
}
