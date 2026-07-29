<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Policies;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Integrations\WooCommerce\Models\WooReconciliationFinding;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Quem vê e resolve os achados da reconciliação (ADR-0025, pasta 19).
 *
 * Mesma alçada do resto do painel — `integrations.manage`: resolver uma
 * divergência é operação de integração (aceitar o novo total como linha de
 * base), não de vendas. Espelha a WooWebhookEventPolicy.
 */
class WooReconciliationFindingPolicy
{
    public function viewAny(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::IntegrationsManage->value);
    }

    public function resolve(Authorizable $usuario, WooReconciliationFinding $achado): bool
    {
        return $usuario->can(Permission::IntegrationsManage->value);
    }
}
