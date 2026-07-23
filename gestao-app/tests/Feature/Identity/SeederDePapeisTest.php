<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\Identity\RolePermissionSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

it('cria os papéis e permissões do enum', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(RoleModel::query()->pluck('name')->all())
        ->toEqualCanonicalizing(Role::values());

    expect(PermissionModel::query()->pluck('name')->all())
        ->toEqualCanonicalizing(Permission::values());
});

it('roda duas vezes sem duplicar nada', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    expect(RoleModel::query()->count())->toBe(count(Role::cases()));
    expect(PermissionModel::query()->count())->toBe(count(Permission::cases()));

    // E as atribuições também não duplicam.
    $admin = RoleModel::findByName(Role::Admin->value);
    expect($admin->permissions)->toHaveCount(count(Permission::cases()));
});

it('revoga do papel a permissão que foi tirada dele', function () {
    $this->seed(RolePermissionSeeder::class);

    // Simula o estado anterior a uma revogação: alguém concedeu ao
    // contador uma permissão de escrita que a matriz não prevê.
    $contador = RoleModel::findByName(Role::Accountant->value);
    $contador->givePermissionTo(Permission::FiscalEmit->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($contador->fresh()->hasPermissionTo(Permission::FiscalEmit->value))->toBeTrue();

    // Rodar o seeder de novo tem de convergir para a matriz. Se apenas
    // concedesse o que falta, revogar acesso exigiria mão na massa em
    // produção — e ninguém faria.
    $this->seed(RolePermissionSeeder::class);

    expect($contador->fresh()->hasPermissionTo(Permission::FiscalEmit->value))->toBeFalse();
});

it('remove permissão órfã que saiu do enum', function () {
    PermissionModel::findOrCreate('modulo.extinto', config('auth.defaults.guard'));

    $this->seed(RolePermissionSeeder::class);

    expect(PermissionModel::query()->where('name', 'modulo.extinto')->exists())->toBeFalse();
});

it('deixa os usuários existentes com o acesso certo depois de reexecutado', function () {
    $this->seed(RolePermissionSeeder::class);

    $vendedora = User::factory()->create()->assignRoleEnum(Role::Sales);

    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($vendedora->fresh()->can(Permission::SalesCreate->value))->toBeTrue();
    expect($vendedora->fresh()->can(Permission::InventoryAdjust->value))->toBeFalse();
});
