<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;

/**
 * Quem pode mexer em contas (BR-801, permissão `users.manage`).
 *
 * O `Gate::before` do IdentityServiceProvider já concede tudo ao admin —
 * estas regras existem para o dia em que `users.manage` for dada a outro
 * papel, e para as nuances que uma permissão sozinha não expressa.
 */
class UserPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can(Permission::UsersManage->value);
    }

    public function view(User $usuario, User $alvo): bool
    {
        return $usuario->can(Permission::UsersManage->value);
    }

    public function create(User $usuario): bool
    {
        return $usuario->can(Permission::UsersManage->value);
    }

    public function update(User $usuario, User $alvo): bool
    {
        return $usuario->can(Permission::UsersManage->value);
    }

    /**
     * Mudar o status de uma conta.
     *
     * Ninguém suspende ou desativa a própria conta: seria trancar-se do
     * lado de fora, e num sistema onde `users.manage` pode estar com uma
     * só pessoa isso deixa a operação sem administrador.
     */
    public function changeStatus(User $usuario, User $alvo, UserStatus $destino): bool
    {
        if (! $usuario->can(Permission::UsersManage->value)) {
            return false;
        }

        if ($usuario->is($alvo)) {
            return false;
        }

        return $alvo->status->canTransitionTo($destino);
    }

    /**
     * Alterar os papéis de uma conta.
     *
     * Também não vale para si mesmo — caso contrário qualquer pessoa com
     * `users.manage` poderia se promover a admin, e a permissão deixaria
     * de ser uma fronteira.
     */
    public function assignRoles(User $usuario, User $alvo): bool
    {
        return $usuario->can(Permission::UsersManage->value)
            && ! $usuario->is($alvo);
    }
}
