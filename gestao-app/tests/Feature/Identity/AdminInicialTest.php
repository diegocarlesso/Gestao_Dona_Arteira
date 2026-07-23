<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\Identity\AdminInicialSeeder;
use Database\Seeders\Identity\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('cria um admin capaz de tudo, com troca de senha obrigatória', function () {
    $this->seed(AdminInicialSeeder::class);

    $admin = User::query()->where('email', 'admin@donaarteira.com.br')->sole();

    expect($admin->hasRoleEnum(Role::Admin))->toBeTrue();
    expect($admin->must_change_password)->toBeTrue();
    expect($admin->canAuthenticate())->toBeTrue();
    expect($admin->can(Permission::UsersManage->value))->toBeTrue();
});

it('não redefine a senha de um admin que já existe', function () {
    $this->seed(AdminInicialSeeder::class);

    $senhaOriginal = User::query()->where('email', 'admin@donaarteira.com.br')->sole()->password;

    $this->seed(AdminInicialSeeder::class);

    $admin = User::query()->where('email', 'admin@donaarteira.com.br')->sole();

    // Reexecutar o seeder num banco em uso não pode derrubar o acesso de
    // quem está trabalhando — nem reabrir a conta com senha conhecida.
    expect(User::query()->where('email', 'admin@donaarteira.com.br')->count())->toBe(1);
    expect($admin->password)->toBe($senhaOriginal);
});

it('não deixa senha em texto no banco', function () {
    $this->seed(AdminInicialSeeder::class);

    $hash = User::query()->where('email', 'admin@donaarteira.com.br')->sole()->password;

    expect($hash)->toStartWith('$');
    expect(strlen($hash))->toBeGreaterThan(50);
});
