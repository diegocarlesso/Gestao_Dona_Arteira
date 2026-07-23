<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use Database\Seeders\Identity\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| conta.ativa — pasta 18 §3
|--------------------------------------------------------------------------
*/

it('deixa conta ativa navegar normalmente', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk();
});

it('derruba a sessão já aberta de quem foi suspenso', function () {
    // Este é o ponto do middleware. Uma checagem apenas no login
    // deixaria a pessoa suspensa trabalhando até o cookie expirar.
    $usuario = User::factory()->create();

    $this->actingAs($usuario)->get('/dashboard')->assertOk();

    $usuario->update(['status' => UserStatus::Suspended]);

    $this->get('/dashboard')->assertRedirect('/login');
    expect(auth()->check())->toBeFalse();
});

it('barra conta desativada e convidada', function (UserStatus $status) {
    $usuario = User::factory()->create(['status' => $status]);

    $this->actingAs($usuario)
        ->get('/dashboard')
        ->assertRedirect('/login');

    expect(auth()->check())->toBeFalse();
})->with([
    'desativada' => UserStatus::Disabled,
    'convidada' => UserStatus::Invited,
]);

it('explica por que o acesso foi negado', function () {
    $this->actingAs(User::factory()->suspended()->create())
        ->get('/dashboard')
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toContain('suspensa');
});

it('protege também as telas de configuração e de usuários', function (string $rota) {
    $this->actingAs(User::factory()->suspended()->create())
        ->get($rota)
        ->assertRedirect('/login');
})->with([
    '/settings/profile',
    '/usuarios',
]);

/*
|--------------------------------------------------------------------------
| senha.trocada — pasta 18 §5
|--------------------------------------------------------------------------
*/

it('prende na tela de troca quem ainda usa senha provisória', function () {
    $usuario = User::factory()->create();
    $usuario->forceFill(['must_change_password' => true])->save();

    $this->actingAs($usuario)
        ->get('/dashboard')
        ->assertRedirect(route('identity.password.forced.edit'));
});

it('deixa alcançar a própria tela de troca, sem laço de redirecionamento', function () {
    $usuario = User::factory()->create();
    $usuario->forceFill(['must_change_password' => true])->save();

    $this->actingAs($usuario)
        ->get('/trocar-senha')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('identity/forced-password'));
});

it('libera a navegação depois da troca', function () {
    $usuario = User::factory()->create(['password' => bcrypt('senha-provisoria-longa')]);
    $usuario->forceFill(['must_change_password' => true])->save();

    $this->actingAs($usuario)
        ->put('/trocar-senha', [
            'current_password' => 'senha-provisoria-longa',
            'password' => 'uma-senha-nova-bem-comprida-2026',
            'password_confirmation' => 'uma-senha-nova-bem-comprida-2026',
        ])
        ->assertRedirect(route('dashboard'));

    $usuario->refresh();

    expect($usuario->must_change_password)->toBeFalse();
    expect($usuario->password_changed_at)->not->toBeNull();

    $this->get('/dashboard')->assertOk();
});

it('exige a senha provisória correta para trocar', function () {
    $usuario = User::factory()->create(['password' => bcrypt('senha-provisoria-longa')]);
    $usuario->forceFill(['must_change_password' => true])->save();

    $this->actingAs($usuario)
        ->put('/trocar-senha', [
            'current_password' => 'chute-errado',
            'password' => 'uma-senha-nova-bem-comprida-2026',
            'password_confirmation' => 'uma-senha-nova-bem-comprida-2026',
        ])
        ->assertSessionHasErrors('current_password');

    expect($usuario->fresh()->must_change_password)->toBeTrue();
});

it('recusa senha com menos de 12 caracteres', function () {
    $usuario = User::factory()->create(['password' => bcrypt('senha-provisoria-longa')]);
    $usuario->forceFill(['must_change_password' => true])->save();

    $this->actingAs($usuario)
        ->put('/trocar-senha', [
            'current_password' => 'senha-provisoria-longa',
            'password' => 'curta123',
            'password_confirmation' => 'curta123',
        ])
        ->assertSessionHasErrors('password');
});

it('não interfere em quem já trocou a senha', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk();
});
