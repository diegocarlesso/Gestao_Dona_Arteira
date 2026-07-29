<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Policies;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Integrations\WooCommerce\Models\WooWebhookEvent;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Quem vê e reprocessa os eventos da integração (BR-801, pasta 19).
 *
 * O painel é operação de integração — alçada `integrations.manage`, a mesma
 * que configura a sync. Não é a de vendas nem a de estoque: quem opera
 * pedidos não necessariamente reprocessa webhook do site.
 */
class WooWebhookEventPolicy
{
    public function viewAny(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::IntegrationsManage->value);
    }

    public function reprocess(Authorizable $usuario, WooWebhookEvent $evento): bool
    {
        return $usuario->can(Permission::IntegrationsManage->value);
    }
}
