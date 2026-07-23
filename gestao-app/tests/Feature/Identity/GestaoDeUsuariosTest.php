<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Events\UserInvited;
use App\Modules\Identity\Events\UserRolesChanged;
use App\Modules\Identity\Events\UserStatusChanged;
use App\Modules\Identity\Models\User;
use Database\Seeders\Identity\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create()->assignRoleEnum(Role::Admin);
});

/*
|--------------------------------------------------------------------------
| Autorização — BR-801, pasta 19 §5
|--------------------------------------------------------------------------
|
| "Papel sem permissão recebe 403, testado explicitamente."
|
*/

it('nega a gestão de usuários a todo papel sem users.manage', function (Role $papel) {
    $this->actingAs(User::factory()->create()->assignRoleEnum($papel))
        ->get('/usuarios')
        ->assertForbidden();
})->with([
    'produção' => Role::Production,
    'vendas' => Role::Sales,
    'expedição' => Role::Fulfillment,
    'financeiro' => Role::Finance,
    'contador' => Role::Accountant,
]);

it('nega a criação de conta a quem não administra', function () {
    $vendas = User::factory()->create()->assignRoleEnum(Role::Sales);

    $this->actingAs($vendas)->get('/usuarios/novo')->assertForbidden();
    $this->actingAs($vendas)->post('/usuarios', [
        'name' => 'Intruso',
        'email' => 'intruso@exemplo.test',
    ])->assertForbidden();

    expect(User::query()->where('email', 'intruso@exemplo.test')->exists())->toBeFalse();
});

it('permite ao admin listar', function () {
    $this->actingAs($this->admin)
        ->get('/usuarios')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('identity/users/index')
            ->has('usuarios.data')
        );
});

/*
|--------------------------------------------------------------------------
| Criação de conta — pasta 18 §3
|--------------------------------------------------------------------------
*/

it('cria a conta aguardando ativação, sem senha utilizável', function () {
    Event::fake([UserInvited::class]);

    $this->actingAs($this->admin)
        ->post('/usuarios', [
            'name' => 'Ana Ateliê',
            'email' => 'ana@donaarteira.com.br',
            'roles' => [Role::Production->value],
        ])
        ->assertRedirect('/usuarios');

    $ana = User::query()->where('email', 'ana@donaarteira.com.br')->sole();

    expect($ana->status)->toBe(UserStatus::Invited);
    expect($ana->canAuthenticate())->toBeFalse();
    expect($ana->hasRoleEnum(Role::Production))->toBeTrue();
    expect($ana->public_id)->toHaveLength(26);

    Event::assertDispatched(UserInvited::class);
});

it('recusa e-mail repetido', function () {
    User::factory()->create(['email' => 'ja@existe.test']);

    $this->actingAs($this->admin)
        ->post('/usuarios', ['name' => 'Outra', 'email' => 'ja@existe.test'])
        ->assertSessionHasErrors('email');
});

it('recusa papel que não existe no enum', function () {
    $this->actingAs($this->admin)
        ->post('/usuarios', [
            'name' => 'Alguém',
            'email' => 'alguem@exemplo.test',
            'roles' => ['superusuario-inventado'],
        ])
        ->assertSessionHasErrors('roles.0');
});

it('cria conta sem papel algum, que não acessa nada (BR-801)', function () {
    $this->actingAs($this->admin)
        ->post('/usuarios', ['name' => 'Sem Papel', 'email' => 'sempapel@exemplo.test']);

    $u = User::query()->where('email', 'sempapel@exemplo.test')->sole();

    expect($u->roleEnums())->toBeEmpty();
    expect($u->can('catalog.view'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Edição — papéis e ciclo de vida
|--------------------------------------------------------------------------
*/

it('sincroniza papéis, removendo o que foi desmarcado', function () {
    Event::fake([UserRolesChanged::class]);

    $alvo = User::factory()->create()->assignRoleEnum(Role::Sales, Role::Fulfillment);

    $this->actingAs($this->admin)
        ->put("/usuarios/{$alvo->public_id}", [
            'name' => $alvo->name,
            'email' => $alvo->email,
            'roles' => [Role::Sales->value],
            'status' => $alvo->status->value,
        ])
        ->assertRedirect('/usuarios');

    $alvo->refresh();

    expect($alvo->hasRoleEnum(Role::Sales))->toBeTrue();
    expect($alvo->hasRoleEnum(Role::Fulfillment))->toBeFalse();

    Event::assertDispatched(UserRolesChanged::class);
});

it('suspende uma conta e dispara o evento', function () {
    Event::fake([UserStatusChanged::class]);

    $alvo = User::factory()->create();

    $this->actingAs($this->admin)
        ->put("/usuarios/{$alvo->public_id}", [
            'name' => $alvo->name,
            'email' => $alvo->email,
            'roles' => [],
            'status' => UserStatus::Suspended->value,
        ])
        ->assertRedirect('/usuarios');

    expect($alvo->fresh()->status)->toBe(UserStatus::Suspended);

    Event::assertDispatched(UserStatusChanged::class);
});

it('recusa transição que o ciclo de vida não permite', function () {
    // Desativada é terminal: reativar ressuscitaria um acesso que
    // alguém decidiu encerrar.
    $alvo = User::factory()->disabled()->create();

    $this->actingAs($this->admin)
        ->put("/usuarios/{$alvo->public_id}", [
            'name' => $alvo->name,
            'email' => $alvo->email,
            'roles' => [],
            'status' => UserStatus::Active->value,
        ])
        ->assertForbidden();

    expect($alvo->fresh()->status)->toBe(UserStatus::Disabled);
});

/*
|--------------------------------------------------------------------------
| Proteção do próprio autor
|--------------------------------------------------------------------------
|
| O Gate::before do admin é excluído destas habilidades de propósito —
| sem isso, o atalho do admin curto-circuitaria a Policy.
|
*/

it('impede o admin de suspender a própria conta', function () {
    $this->actingAs($this->admin)
        ->put("/usuarios/{$this->admin->public_id}", [
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'roles' => [Role::Admin->value],
            'status' => UserStatus::Suspended->value,
        ])
        ->assertForbidden();

    expect($this->admin->fresh()->status)->toBe(UserStatus::Active);
});

it('impede o admin de alterar os próprios papéis', function () {
    $this->actingAs($this->admin)
        ->put("/usuarios/{$this->admin->public_id}", [
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'roles' => [],
            'status' => $this->admin->status->value,
        ])
        ->assertForbidden();

    expect($this->admin->fresh()->hasRoleEnum(Role::Admin))->toBeTrue();
});

it('deixa o admin corrigir o próprio nome', function () {
    $this->actingAs($this->admin)
        ->put("/usuarios/{$this->admin->public_id}", [
            'name' => 'Nome Corrigido',
            'email' => $this->admin->email,
            'roles' => [Role::Admin->value],
            'status' => $this->admin->status->value,
        ])
        ->assertRedirect('/usuarios');

    expect($this->admin->fresh()->name)->toBe('Nome Corrigido');
});

/*
|--------------------------------------------------------------------------
| Props das telas — pasta 06 §7
|--------------------------------------------------------------------------
*/

it('entrega à tela de edição só as transições possíveis', function () {
    $alvo = User::factory()->disabled()->create();

    $this->actingAs($this->admin)
        ->get("/usuarios/{$alvo->public_id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('identity/users/edit')
            ->where('ehVoce', false)
            // Desativada é terminal: só ela própria na lista.
            ->has('situacoesDisponiveis', 1)
        );
});

it('marca a própria conta na tela de edição', function () {
    $this->actingAs($this->admin)
        ->get("/usuarios/{$this->admin->public_id}")
        ->assertInertia(fn ($page) => $page->where('ehVoce', true));
});

it('usa o public_id na URL, nunca o id sequencial', function () {
    $alvo = User::factory()->create();

    $this->actingAs($this->admin)->get("/usuarios/{$alvo->id}")->assertNotFound();
    $this->actingAs($this->admin)->get("/usuarios/{$alvo->public_id}")->assertOk();
});

it('filtra a listagem por nome e e-mail', function () {
    User::factory()->create(['name' => 'Mariana Gesso', 'email' => 'mariana@exemplo.test']);
    User::factory()->create(['name' => 'Outro Alguém', 'email' => 'outro@exemplo.test']);

    $this->actingAs($this->admin)
        ->get('/usuarios?busca=mariana')
        ->assertInertia(fn ($page) => $page->has('usuarios.data', 1));
});

/*
|--------------------------------------------------------------------------
| Props compartilhadas — pasta 06 §6
|--------------------------------------------------------------------------
*/

it('compartilha as permissões para a UI esconder o que não pode', function () {
    $this->actingAs(User::factory()->create()->assignRoleEnum(Role::Sales))
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            // O assertInertia entrega Collection, não array.
            ->where('auth.permissions', fn ($p) => $p->contains('sales.create')
                && ! $p->contains('users.manage'))
        );
});

it('nunca compartilha senha nem segredo de 2FA', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->missing('auth.user.password')
            ->missing('auth.user.two_factor_secret')
            ->missing('auth.user.remember_token')
        );
});
