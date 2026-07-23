<?php

declare(strict_types=1);

namespace Database\Seeders\Identity;

use App\Modules\Identity\Enums\Permission as PermissionEnum;
use App\Modules\Identity\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Materializa no banco a matriz papel × permissão de docs/19-Permissoes §3.
 *
 * Papéis são conjuntos versionados por seeder (ADR-0011): mudar acesso é
 * mudar o enum `Role` e rodar isto de novo, não editar linha à mão em
 * produção — mudança manual não deixa rastro nem se repete no próximo
 * ambiente.
 *
 * **Idempotente e convergente**: rodar duas vezes dá o mesmo resultado, e
 * permissão removida do enum é removida do papel. Sem essa segunda parte,
 * revogar acesso exigiria intervenção manual — exatamente o que o seeder
 * existe para evitar.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // O pacote cacheia permissões; sem limpar antes, o próprio
        // seeder pode decidir sobre um retrato velho do banco.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard');

        foreach (PermissionEnum::cases() as $permissao) {
            Permission::findOrCreate($permissao->value, $guard);
        }

        foreach (RoleEnum::cases() as $papel) {
            $role = Role::findOrCreate($papel->value, $guard);

            // syncPermissions converge: concede o que falta e revoga o
            // que saiu do enum.
            $role->syncPermissions($papel->permissionValues());
        }

        // Permissão que deixou de existir no enum não pode continuar
        // atribuível — ficaria órfã no banco, invisível na pasta 19 e
        // ainda concedível pela tela de papéis.
        Permission::query()
            ->whereNotIn('name', PermissionEnum::values())
            ->where('guard_name', $guard)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
