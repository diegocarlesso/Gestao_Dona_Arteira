<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Events\PasswordChanged;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ChangeUserStatusService;
use App\Modules\Identity\Services\RecordSecurityEvent;
use App\Modules\Identity\Services\SyncUserRolesService;
use Database\Seeders\Identity\RolePermissionSeeder;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Trilha de segurança — pasta 26 §2.1
|--------------------------------------------------------------------------
|
| O canal `security_events`. Cada teste aqui existe porque a ausência do
| registro correspondente deixaria uma pergunta de investigação sem
| resposta.
|
*/

function evento(SecurityEventType $tipo): ?SecurityEvent
{
    return SecurityEvent::query()->where('event', $tipo->value)->latest('id')->first();
}

it('registra a entrada autorizada e preenche o last_login_at', function () {
    $usuario = User::factory()->create(['password' => bcrypt('senha-longa-de-teste')]);

    expect($usuario->last_login_at)->toBeNull();

    $this->post('/login', [
        'email' => $usuario->email,
        'password' => 'senha-longa-de-teste',
    ])->assertRedirect();

    $registro = evento(SecurityEventType::LoginOk);

    expect($registro)->not->toBeNull()
        ->and($registro->user_id)->toBe($usuario->id)
        ->and($registro->actor_id)->toBe($usuario->id)
        ->and($registro->ip_address)->not->toBeNull();

    // A coluna existia desde o início do Identity e nunca era preenchida
    // — é o que a revisão trimestral de acessos consulta para saber se
    // uma conta ainda é usada.
    expect($usuario->fresh()->last_login_at)->not->toBeNull();
});

it('registra a tentativa recusada com o e-mail digitado, mesmo sem conta', function () {
    $this->post('/login', [
        'email' => 'ninguem@exemplo.test',
        'password' => 'chute-qualquer',
    ]);

    $registro = evento(SecurityEventType::LoginFailed);

    expect($registro)->not->toBeNull()
        // Sem conta correspondente não há user_id — e é justamente o caso
        // em que a coluna `identifier` é a única pista.
        ->and($registro->user_id)->toBeNull()
        ->and($registro->identifier)->toBe('ninguem@exemplo.test')
        ->and($registro->context['conta_existe'])->toBeFalse();
});

it('distingue senha errada de conta existente da tentativa contra conta inexistente', function () {
    $usuario = User::factory()->create(['password' => bcrypt('senha-longa-de-teste')]);

    $this->post('/login', ['email' => $usuario->email, 'password' => 'errada']);

    $registro = evento(SecurityEventType::LoginFailed);

    expect($registro->user_id)->toBe($usuario->id)
        ->and($registro->context['conta_existe'])->toBeTrue();
});

it('nunca grava a senha digitada na trilha', function () {
    $this->post('/login', [
        'email' => 'alguem@exemplo.test',
        'password' => 'ESTA-SENHA-NAO-PODE-VAZAR',
    ]);

    // O evento `Failed` do Laravel carrega `credentials`, com a senha
    // dentro. Gravar o array inteiro em `context` colocaria senha em texto
    // puro numa tabela que se lê com `audit.view`.
    $tudo = SecurityEvent::query()->get()->toJson();

    expect($tudo)->not->toContain('ESTA-SENHA-NAO-PODE-VAZAR');
});

it('registra a saída', function () {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)->post('/logout');

    expect(evento(SecurityEventType::Logout)?->user_id)->toBe($usuario->id);
});

it('registra a troca de senha e quem a fez', function () {
    $usuario = User::factory()->create();

    PasswordChanged::dispatch($usuario, obrigatoria: true);

    $registro = evento(SecurityEventType::PasswordChanged);

    expect($registro->user_id)->toBe($usuario->id)
        ->and($registro->context['obrigatoria'])->toBeTrue();
});

it('registra mudança de status com o de, o para e o efeito na autenticação', function () {
    $admin = User::factory()->create()->assignRoleEnum(Role::Admin);
    $alvo = User::factory()->create(['status' => UserStatus::Active]);

    $this->actingAs($admin);

    app(ChangeUserStatusService::class)->handle($alvo, UserStatus::Suspended);

    $registro = evento(SecurityEventType::UserStatusChanged);

    expect($registro->user_id)->toBe($alvo->id)
        // O ator é o admin, não o alvo: é a pergunta "quem suspendeu quem".
        ->and($registro->actor_id)->toBe($admin->id)
        ->and($registro->context['de'])->toBe(UserStatus::Active->value)
        ->and($registro->context['para'])->toBe(UserStatus::Suspended->value)
        ->and($registro->context['autentica_depois'])->toBeFalse();
});

it('registra mudança de papéis com o diff explícito', function () {
    $admin = User::factory()->create()->assignRoleEnum(Role::Admin);
    $alvo = User::factory()->create()->assignRoleEnum(Role::Sales);

    $this->actingAs($admin);

    app(SyncUserRolesService::class)->handle($alvo, [Role::Finance]);

    $registro = evento(SecurityEventType::UserRolesChanged);

    // Papel vive em tabela pivô, e o laravel-auditing audita colunas de
    // model: sem este registro, ganhar acesso não deixaria rastro algum.
    expect($registro->user_id)->toBe($alvo->id)
        ->and($registro->actor_id)->toBe($admin->id)
        ->and($registro->context['ganhou'])->toBe([Role::Finance->value])
        ->and($registro->context['perdeu'])->toBe([Role::Sales->value]);
});

it('registra o acesso negado por permissão, com a rota', function () {
    // Conta sem `users.manage` — a listagem tem de recusar.
    $usuario = User::factory()->create()->assignRoleEnum(Role::Production);

    $this->actingAs($usuario)->get('/usuarios')->assertForbidden();

    $registro = evento(SecurityEventType::PermissionDenied);

    expect($registro)->not->toBeNull()
        ->and($registro->user_id)->toBe($usuario->id)
        ->and($registro->context['metodo'])->toBe('GET');
});

it('não registra negativas especulativas de can() na UI', function () {
    $usuario = User::factory()->create()->assignRoleEnum(Role::Production);

    // A UI pergunta dezenas de vezes por página se pode mostrar um botão.
    // Se cada negativa virasse linha, o sinal desapareceria no ruído — é
    // por isso que o registro está no handler da exceção e não em
    // Gate::after.
    $this->actingAs($usuario);

    for ($i = 0; $i < 5; $i++) {
        $usuario->can('users.manage');
    }

    expect(SecurityEvent::query()->ofType(SecurityEventType::PermissionDenied)->count())->toBe(0);
});

it('é imutável: não deixa editar nem apagar uma entrada', function () {
    $usuario = User::factory()->create();
    $registro = app(RecordSecurityEvent::class)->record(SecurityEventType::LoginOk, sobre: $usuario);

    $registro->identifier = 'adulterado';

    // A imutabilidade da pasta 26 §3 é invariante do model, não
    // recomendação de documento: trilha editável não prova nada.
    expect($registro->save())->toBeFalse()
        ->and($registro->delete())->toBeFalse()
        ->and(SecurityEvent::query()->find($registro->id)->identifier)->toBeNull();
});

it('não propaga a exceção quando a gravação da trilha falha, e grita no log', function () {
    Log::spy();

    // Uma conta que não existe no banco: a FK de `user_id` recusa a
    // inserção. É a forma mais barata de reproduzir a falha que, se
    // propagasse, trancaria todo mundo para fora do ERP — o formato da
    // armadilha P-16, em que o mecanismo de vigilância derruba a
    // aplicação que ele existe para proteger.
    //
    // Preferido a derrubar a tabela: DDL faz commit implícito no MySQL, o
    // rollback do RefreshDatabase não desfaria, e os testes seguintes
    // rodariam sem a tabela.
    $fantasma = new User;
    $fantasma->id = 999_999_999;

    $registro = app(RecordSecurityEvent::class)
        ->record(SecurityEventType::LoginOk, sobre: $fantasma);

    // Devolve null em vez de estourar — quem chama é um listener de login.
    expect($registro)->toBeNull();

    // `critical` e não `error`: a trilha de segurança ter parado de gravar
    // é incidente, não aviso.
    Log::shouldHaveReceived('critical')
        ->withArgs(fn (string $mensagem): bool => str_contains($mensagem, 'trilha de segurança'));
});
