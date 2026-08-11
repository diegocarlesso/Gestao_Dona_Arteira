<?php

declare(strict_types=1);

namespace App\Modules\Finance\Policies;

use App\Modules\Identity\Enums\Permission;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Quem cadastra contas financeiras e categorias — pasta 19.
 *
 * `finance.manage`, mais restrita que `finance.view`/`finance.settle`:
 * mexer no plano de contas ou nas contas bancárias afeta todo relatório
 * daí em diante, não só o título que se está olhando.
 */
class FinanceSettingsPolicy
{
    public function viewAny(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::FinanceView->value);
    }

    public function view(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::FinanceView->value);
    }

    public function create(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::FinanceManage->value);
    }

    public function update(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::FinanceManage->value);
    }

    public function delete(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::FinanceManage->value);
    }
}
