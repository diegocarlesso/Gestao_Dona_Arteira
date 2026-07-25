<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\ConviteDeAcesso;
use App\Modules\Identity\Services\InviteUserService;
use Database\Seeders\Identity\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

use function Pest\Laravel\post;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Convite e ativação — pasta 18 §3
|--------------------------------------------------------------------------
|
| O ciclo que o diagrama da pasta 18 sempre descreveu — convite → define
| senha → ativa → entra — e que até 2026-07-24 parava no meio: nada
| promovia `Invited` a `Active`, então a pessoa definia a senha e mesmo
| assim era barrada pelo middleware `conta.ativa`.
|
*/

const SENHA_NOVA = 'senha-longa-de-convite-987';

it('envia o convite por e-mail ao criar a conta', function () {
    Notification::fake();

    $convidado = app(InviteUserService::class)->handle(
        'Mariana Gesso',
        'mariana@exemplo.test',
        [Role::Production],
    );

    Notification::assertSentTo($convidado, ConviteDeAcesso::class);
});

it('registra o convite na trilha mesmo que o envio seja adiado', function () {
    Notification::fake();

    app(InviteUserService::class)->handle('Ana', 'ana@exemplo.test', [Role::Sales]);

    // A notificação vai para a fila; a trilha não. Registrar o convite não
    // pode depender de a fila estar viva.
    expect(SecurityEvent::query()->where('event', SecurityEventType::UserInvited->value)->count())->toBe(1);
});

it('só despacha o e-mail depois do commit da transação', function () {
    // O `UserInvited` nasce dentro do DB::transaction do Service. Sem
    // `$afterCommit`, o worker poderia pegar o job antes do commit e não
    // achar o usuário — um convite que morre num erro que ninguém vê.
    expect((new ConviteDeAcesso)->afterCommit)->toBeTrue();
});

it('não deixa o convidado entrar antes de definir a senha', function () {
    $convidado = app(InviteUserService::class)->handle('Rita', 'rita@exemplo.test', [Role::Sales]);

    expect($convidado->status)->toBe(UserStatus::Invited)
        ->and($convidado->canAuthenticate())->toBeFalse();
});

it('ativa a conta quando o convidado define a senha', function () {
    Notification::fake();

    $convidado = app(InviteUserService::class)->handle('Rita', 'rita@exemplo.test', [Role::Sales]);
    $token = Password::broker()->createToken($convidado);

    post('/reset-password', [
        'token' => $token,
        'email' => $convidado->email,
        'password' => SENHA_NOVA,
        'password_confirmation' => SENHA_NOVA,
    ])->assertRedirect('/login')->assertSessionHasNoErrors();

    // Definir a senha **é** a ativação (pasta 18 §3) — era o elo que
    // faltava, e sem ele a pessoa ficava presa num laço: o erro do login
    // a mandava conferir o convite, que não resolvia nada.
    expect($convidado->refresh()->status)->toBe(UserStatus::Active)
        ->and($convidado->canAuthenticate())->toBeTrue()
        ->and($convidado->password_changed_at)->not->toBeNull();
});

it('deixa a trilha registrar a ativação', function () {
    Notification::fake();

    $convidado = app(InviteUserService::class)->handle('Rita', 'rita@exemplo.test', [Role::Sales]);

    post('/reset-password', [
        'token' => Password::broker()->createToken($convidado),
        'email' => $convidado->email,
        'password' => SENHA_NOVA,
        'password_confirmation' => SENHA_NOVA,
    ]);

    $evento = SecurityEvent::query()
        ->where('event', SecurityEventType::UserStatusChanged->value)
        ->latest('id')
        ->first();

    // Conta que ganha acesso sem rastro é conta que ninguém sabe quando
    // passou a poder entrar.
    expect($evento)->not->toBeNull()
        ->and($evento->context['de'] ?? null)->toBe(UserStatus::Invited->value)
        ->and($evento->context['para'] ?? null)->toBe(UserStatus::Active->value);
});

it('fecha o ciclo: convidado define a senha e entra', function () {
    Notification::fake();

    $convidado = app(InviteUserService::class)->handle('Rita', 'rita@exemplo.test', [Role::Sales]);

    post('/reset-password', [
        'token' => Password::broker()->createToken($convidado),
        'email' => $convidado->email,
        'password' => SENHA_NOVA,
        'password_confirmation' => SENHA_NOVA,
    ]);

    post('/login', ['email' => $convidado->email, 'password' => SENHA_NOVA])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($convidado->fresh());
});

it('não reativa conta suspensa por redefinição de senha', function () {
    $suspensa = User::factory()->suspended()->create();

    post('/reset-password', [
        'token' => Password::broker()->createToken($suspensa),
        'email' => $suspensa->email,
        'password' => SENHA_NOVA,
        'password_confirmation' => SENHA_NOVA,
    ])->assertSessionHasNoErrors();

    // Quem foi suspenso não volta pedindo "esqueci minha senha" —
    // reativar é decisão de quem administra (pasta 18 §3).
    expect($suspensa->refresh()->status)->toBe(UserStatus::Suspended)
        ->and($suspensa->canAuthenticate())->toBeFalse();
});

it('exige a política de senha completa também na redefinição', function () {
    $convidado = app(InviteUserService::class)->handle('Rita', 'rita@exemplo.test', [Role::Sales]);

    // Este era o TERCEIRO caminho de definição de senha e continuava com
    // `Password::defaults()` — 8 caracteres, sem checagem de vazamento —
    // depois que os outros dois foram unificados na PasswordPolicy.
    post('/reset-password', [
        'token' => Password::broker()->createToken($convidado),
        'email' => $convidado->email,
        'password' => 'Curta1!',
        'password_confirmation' => 'Curta1!',
    ])->assertSessionHasErrors('password');

    expect($convidado->refresh()->status)->toBe(UserStatus::Invited);
});

it('recusa senha longa porém vazada na redefinição', function () {
    $vazada = 'senha-que-vazou-em-2019';

    // O `Tests\TestCase` falsifica o HaveIBeenPwned para a suíte inteira;
    // aqui basta declarar o que a API deve reportar como vazado.
    $this->senhasVazadas = [$vazada];

    $convidado = app(InviteUserService::class)->handle('Rita', 'rita@exemplo.test', [Role::Sales]);

    post('/reset-password', [
        'token' => Password::broker()->createToken($convidado),
        'email' => $convidado->email,
        'password' => $vazada,
        'password_confirmation' => $vazada,
    ])->assertSessionHasErrors('password');

    expect($convidado->refresh()->status)->toBe(UserStatus::Invited);
});
