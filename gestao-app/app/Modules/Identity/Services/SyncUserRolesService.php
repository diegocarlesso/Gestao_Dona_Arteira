<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Events\UserRolesChanged;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Define os papéis de uma conta (pasta 19).
 *
 * Sincroniza em vez de acrescentar: a tela mostra o conjunto completo, e
 * salvar precisa **remover** o que foi desmarcado. Um `assignRole` puro
 * só somaria, e tirar acesso viraria operação manual no banco.
 */
class SyncUserRolesService
{
    /**
     * @param  list<Role>  $papeis
     */
    public function handle(User $usuario, array $papeis): User
    {
        $antes = $usuario->roleEnums();

        return DB::transaction(function () use ($usuario, $papeis, $antes): User {
            $usuario->syncRoles(array_map(
                static fn (Role $p): string => $p->value,
                $papeis,
            ));

            $depois = $usuario->fresh()->roleEnums();

            // Mudança de papel é evento de segurança (pasta 26 §2): a
            // trilha do laravel-auditing cobre colunas da tabela, e papel
            // vive em tabela pivô — não apareceria lá.
            event(new UserRolesChanged($usuario, $antes, $depois));

            return $usuario->fresh();
        });
    }
}
