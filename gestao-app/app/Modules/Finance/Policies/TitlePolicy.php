<?php

declare(strict_types=1);

namespace App\Modules\Finance\Policies;

use App\Modules\Identity\Enums\Permission;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Quem vê e movimenta títulos — pasta 19, BR-502.
 *
 * Um Policy só para `Payable` **e** `Receivable` (registrado duas vezes no
 * provider): as duas regras são idênticas dos dois lados do caixa, e dois
 * Policies iguais só duplicariam a matriz de permissão.
 */
class TitlePolicy
{
    public function viewAny(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::FinanceView->value);
    }

    public function view(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::FinanceView->value);
    }

    public function settle(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::FinanceSettle->value);
    }

    public function reverse(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::FinanceSettle->value);
    }

    public function manage(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::FinanceManage->value);
    }
}
