<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Policies;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Quem vê, monta e confirma pedido de compra (BR-801, pasta 19).
 *
 * `confirm` é listada separada mesmo apoiando-se na mesma permissão: é a
 * ação que cria dívida (BR-402), e quando a matriz da pasta 19 ganhar um
 * papel de comprador que monta o PC mas não o lança, é este método — e só
 * ele — que muda.
 */
class PurchaseOrderPolicy
{
    public function viewAny(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::PurchasingManage->value);
    }

    public function view(Authorizable $usuario, PurchaseOrder $pedido): bool
    {
        return $usuario->can(Permission::PurchasingManage->value);
    }

    public function create(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::PurchasingManage->value);
    }

    public function update(Authorizable $usuario, PurchaseOrder $pedido): bool
    {
        return $usuario->can(Permission::PurchasingManage->value);
    }

    /**
     * Confirmar — gera a conta a pagar.
     */
    public function confirm(Authorizable $usuario, PurchaseOrder $pedido): bool
    {
        return $usuario->can(Permission::PurchasingManage->value);
    }

    public function cancel(Authorizable $usuario, PurchaseOrder $pedido): bool
    {
        return $usuario->can(Permission::PurchasingManage->value);
    }
}
