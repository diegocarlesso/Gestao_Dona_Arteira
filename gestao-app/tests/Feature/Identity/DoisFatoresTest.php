<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Models\RememberedDevice;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RememberedDeviceService;
use Database\Seeders\Identity\RolePermissionSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Segundo fator TOTP — BR-804, ADR-0021
|--------------------------------------------------------------------------
|
| O fluxo inteiro: configurar, ser obrigado a configurar, o desafio no
| login, e o "lembrar deste dispositivo por 30 dias" — inclusive as duas
| formas de revogá-lo, que são o que torna o mecanismo aceitável.
|
*/

const SENHA = 'senha-longa-de-teste-123';

/** Um usuário com 2FA já confirmado e pronto para entrar. */
function comDoisFatores(Role ...$papeis): User
{
    $usuario = User::factory()->create(['password' => bcrypt(SENHA)]);

    if ($papeis !== []) {
        $usuario->assignRoleEnum(...$papeis);
    }

    app(EnableTwoFactorAuthentication::class)($usuario);
    $usuario->refresh()->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $usuario->refresh();
}

/** O código que o aplicativo autenticador mostraria neste instante. */
function totpDe(User $usuario): string
{
    return app(Google2FA::class)->getCurrentOtp(
        Fortify::currentEncrypter()->decrypt($usuario->two_factor_secret),
    );
}

/**
 * O cookie de dispositivo confiado, **em claro**.
 *
 * Decifrado de propósito: o `withCookie()` do Laravel cifra o que recebe
 * antes de mandar na requisição seguinte. Devolver daqui o valor como veio
 * na resposta (já cifrado) faria uma segunda camada de cifra, e o
 * middleware entregaria ao Service um texto que não é `device_id|token` —
 * um cookie válido seria recusado, e o teste culparia o código errado.
 */
function cookieDeDispositivo(TestResponse $resposta): string
{
    $cookie = $resposta->getCookie(RememberedDeviceService::COOKIE);

    expect($cookie)->not->toBeNull('a resposta deveria trazer o cookie de dispositivo');

    return (string) $cookie->getValue();
}

// ---------------------------------------------------------------- setup

it('não ativa o 2FA só por gerar o segredo', function () {
    $usuario = User::factory()->create();

    actingAs($usuario)->post('/dois-fatores')->assertRedirect('/dois-fatores');

    // Abrir a tela e desistir tem de ser inofensivo: se gerar já ativasse,
    // quem fechasse o navegador no meio ficaria trancado do lado de fora,
    // sem aplicativo configurado e com o 2FA exigido no próximo login.
    expect($usuario->refresh()->two_factor_secret)->not->toBeNull()
        ->and($usuario->hasTwoFactorEnabled())->toBeFalse();
});

it('ativa o 2FA quando o código do aplicativo confere', function () {
    $usuario = User::factory()->create();
    app(EnableTwoFactorAuthentication::class)($usuario);
    $usuario->refresh();

    actingAs($usuario)
        ->post('/dois-fatores/confirmar', ['codigo' => totpDe($usuario)])
        ->assertRedirect('/dois-fatores')
        ->assertSessionHas('codigos_de_recuperacao');

    expect($usuario->refresh()->hasTwoFactorEnabled())->toBeTrue();

    $evento = SecurityEvent::query()->where('event', SecurityEventType::TwoFactorEnabled->value)->first();
    expect($evento)->not->toBeNull()
        ->and($evento->user_id)->toBe($usuario->id);
});

it('recusa a confirmação com código errado', function () {
    $usuario = User::factory()->create();
    app(EnableTwoFactorAuthentication::class)($usuario);

    actingAs($usuario->refresh())
        ->post('/dois-fatores/confirmar', ['codigo' => '000000'])
        ->assertSessionHasErrors('codigo');

    expect($usuario->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('aceita o código com o espaço que o aplicativo mostra', function () {
    $usuario = User::factory()->create();
    app(EnableTwoFactorAuthentication::class)($usuario);
    $usuario->refresh();

    $codigo = totpDe($usuario);
    $comEspaco = substr($codigo, 0, 3).' '.substr($codigo, 3);

    actingAs($usuario)
        ->post('/dois-fatores/confirmar', ['codigo' => $comEspaco])
        ->assertSessionHasNoErrors();

    expect($usuario->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

// ---------------------------------------------------------- enforcement

it('prende quem tem papel que exige 2FA na tela de configuração', function () {
    $admin = User::factory()->create();
    $admin->assignRoleEnum(Role::Admin);

    actingAs($admin)->get('/usuarios')->assertRedirect('/dois-fatores');
    actingAs($admin)->get('/dois-fatores')->assertOk();
});

it('não incomoda quem não tem papel que exige 2FA', function () {
    $producao = User::factory()->create();
    $producao->assignRoleEnum(Role::Production);

    actingAs($producao)->get('/dashboard')->assertOk();
});

it('libera o resto do sistema assim que o 2FA é confirmado', function () {
    $admin = comDoisFatores(Role::Admin);

    actingAs($admin)->get('/usuarios')->assertOk();
});

// ------------------------------------------------------ desafio no login

it('não abre a sessão só com a senha quando a conta usa 2FA', function () {
    $usuario = comDoisFatores();

    post('/login', ['email' => $usuario->email, 'password' => SENHA])
        ->assertRedirect('/entrar/dois-fatores');

    $this->assertGuest();
});

it('não registra login.ok antes do segundo fator', function () {
    $usuario = comDoisFatores();

    post('/login', ['email' => $usuario->email, 'password' => SENHA]);

    // A trilha da pasta 26 tem de dizer a verdade: "entrou" e "acertou a
    // senha" são fatos diferentes, e um desafio abandonado no meio não
    // pode ficar registrado como entrada bem-sucedida.
    expect(SecurityEvent::query()->where('event', SecurityEventType::LoginOk->value)->count())->toBe(0)
        ->and($usuario->refresh()->last_login_at)->toBeNull();
});

it('entra quando o código do aplicativo confere', function () {
    $usuario = comDoisFatores();

    post('/login', ['email' => $usuario->email, 'password' => SENHA]);
    post('/entrar/dois-fatores', ['codigo' => totpDe($usuario)])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($usuario);

    expect(SecurityEvent::query()->where('event', SecurityEventType::LoginOk->value)->count())->toBe(1)
        ->and($usuario->refresh()->last_login_at)->not->toBeNull();
});

it('recusa o desafio com código errado e registra a tentativa', function () {
    $usuario = comDoisFatores();

    post('/login', ['email' => $usuario->email, 'password' => SENHA]);
    post('/entrar/dois-fatores', ['codigo' => '000000'])->assertSessionHasErrors('codigo');

    $this->assertGuest();

    // Falha no segundo fator é a mais interessante das entradas recusadas:
    // significa que alguém já tem a senha certa desta conta.
    $evento = SecurityEvent::query()->where('event', SecurityEventType::LoginFailed->value)->latest('id')->first();
    expect($evento->context['etapa'] ?? null)->toBe('dois_fatores');
});

it('para de aceitar tentativas depois de cinco erros', function () {
    $usuario = comDoisFatores();

    post('/login', ['email' => $usuario->email, 'password' => SENHA]);

    foreach (range(1, 5) as $i) {
        post('/entrar/dois-fatores', ['codigo' => '000000']);
    }

    // Ao estourar, o desafio pendente cai junto: deixá-lo aberto
    // permitiria voltar a martelar quando o limite passasse, sem precisar
    // da senha de novo.
    post('/entrar/dois-fatores', ['codigo' => totpDe($usuario)])->assertRedirect('/login');

    $this->assertGuest();
});

it('devolve ao login quando o desafio expira', function () {
    $usuario = comDoisFatores();

    post('/login', ['email' => $usuario->email, 'password' => SENHA]);

    $this->travel(6)->minutes();

    post('/entrar/dois-fatores', ['codigo' => totpDe($usuario)])->assertRedirect('/login');
    $this->assertGuest();
});

// ------------------------------------------------- códigos de recuperação

it('entra com código de recuperação e o consome', function () {
    $usuario = comDoisFatores();
    $codigos = $usuario->recoveryCodes();

    post('/login', ['email' => $usuario->email, 'password' => SENHA]);
    post('/entrar/dois-fatores', ['codigo' => $codigos[0]])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($usuario);

    // Uso único de verdade — o mesmo papel não entra duas vezes.
    expect($usuario->refresh()->recoveryCodes())->not->toContain($codigos[0])
        ->toHaveCount(8);

    expect(SecurityEvent::query()->where('event', SecurityEventType::TwoFactorRecoveryCodeUsed->value)->count())->toBe(1);
});

it('renova os oito códigos exigindo a senha atual', function () {
    $usuario = comDoisFatores();
    $antigos = $usuario->recoveryCodes();

    actingAs($usuario)
        ->post('/dois-fatores/codigos-de-recuperacao', ['current_password' => SENHA])
        ->assertSessionHasNoErrors();

    expect($usuario->refresh()->recoveryCodes())->toHaveCount(8)
        ->not->toContain($antigos[0]);

    expect(SecurityEvent::query()->where('event', SecurityEventType::TwoFactorRecoveryCodesRegenerated->value)->count())->toBe(1);
});

it('não renova os códigos sem a senha atual', function () {
    $usuario = comDoisFatores();

    actingAs($usuario)
        ->post('/dois-fatores/codigos-de-recuperacao', ['current_password' => 'chute'])
        ->assertSessionHasErrors('current_password');
});

// ------------------------------------------------- dispositivo lembrado

it('lembra o dispositivo quando o TOTP confere e a opção é marcada', function () {
    $usuario = comDoisFatores();

    post('/login', ['email' => $usuario->email, 'password' => SENHA]);
    $resposta = post('/entrar/dois-fatores', [
        'codigo' => totpDe($usuario),
        'lembrar_dispositivo' => true,
    ]);

    cookieDeDispositivo($resposta);

    expect($usuario->rememberedDevices()->count())->toBe(1)
        ->and(SecurityEvent::query()->where('event', SecurityEventType::TwoFactorDeviceRemembered->value)->count())->toBe(1);
});

it('pula o desafio no dispositivo lembrado', function () {
    $usuario = comDoisFatores();

    post('/login', ['email' => $usuario->email, 'password' => SENHA]);
    $resposta = post('/entrar/dois-fatores', [
        'codigo' => totpDe($usuario),
        'lembrar_dispositivo' => true,
    ]);
    $cookie = cookieDeDispositivo($resposta);

    post('/logout');

    $this->withCookie(RememberedDeviceService::COOKIE, $cookie)
        ->post('/login', ['email' => $usuario->email, 'password' => SENHA])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($usuario);
});

it('NÃO lembra o dispositivo quando a entrada foi por código de recuperação', function () {
    $usuario = comDoisFatores();

    post('/login', ['email' => $usuario->email, 'password' => SENHA]);
    $resposta = post('/entrar/dois-fatores', [
        'codigo' => $usuario->recoveryCodes()[0],
        'lembrar_dispositivo' => true,
    ]);

    // Código de recuperação prova posse da lista impressa, não do
    // aplicativo — e é o caminho de "perdi o celular". Conceder 30 dias
    // sem desafio exatamente aí anularia a proteção.
    expect($usuario->rememberedDevices()->count())->toBe(0)
        ->and($resposta->getCookie(RememberedDeviceService::COOKIE))->toBeNull();
});

it('volta a desafiar quando o dispositivo lembrado vence', function () {
    $usuario = comDoisFatores();

    post('/login', ['email' => $usuario->email, 'password' => SENHA]);
    $cookie = cookieDeDispositivo(post('/entrar/dois-fatores', [
        'codigo' => totpDe($usuario),
        'lembrar_dispositivo' => true,
    ]));

    post('/logout');

    $usuario->rememberedDevices()->update(['expires_at' => now()->subDay()]);

    $this->withCookie(RememberedDeviceService::COOKIE, $cookie)
        ->post('/login', ['email' => $usuario->email, 'password' => SENHA])
        ->assertRedirect('/entrar/dois-fatores');

    $this->assertGuest();
});

it('esquece os dispositivos quando a senha é trocada', function () {
    $usuario = comDoisFatores();
    RememberedDevice::factory()->for($usuario)->count(2)->create();

    actingAs($usuario)->put('/settings/password', [
        'current_password' => SENHA,
        'password' => 'outra-senha-longa-de-teste-456',
        'password_confirmation' => 'outra-senha-longa-de-teste-456',
    ])->assertSessionHasNoErrors();

    // É o que torna a revogação uma resposta de verdade a "acho que alguém
    // pegou meu computador": sem isto, trocar a senha não fecharia a porta
    // que o dispositivo lembrado deixou aberta.
    expect($usuario->rememberedDevices()->count())->toBe(0);
});

it('esquece os dispositivos quando o 2FA é desativado', function () {
    $usuario = comDoisFatores();
    RememberedDevice::factory()->for($usuario)->count(2)->create();

    actingAs($usuario)
        ->delete('/dois-fatores', ['current_password' => SENHA])
        ->assertSessionHasNoErrors();

    expect($usuario->refresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and($usuario->rememberedDevices()->count())->toBe(0);

    expect(SecurityEvent::query()->where('event', SecurityEventType::TwoFactorDisabled->value)->count())->toBe(1);
});

it('esquece os dispositivos a pedido do usuário', function () {
    $usuario = comDoisFatores();
    RememberedDevice::factory()->for($usuario)->count(3)->create();

    actingAs($usuario)->delete('/dois-fatores/dispositivos')->assertSessionHasNoErrors();

    expect($usuario->rememberedDevices()->count())->toBe(0);
});

it('ignora dispositivo lembrado de outra conta', function () {
    $usuario = comDoisFatores();
    $outro = comDoisFatores();

    post('/login', ['email' => $outro->email, 'password' => SENHA]);
    $cookieDoOutro = cookieDeDispositivo(post('/entrar/dois-fatores', [
        'codigo' => totpDe($outro),
        'lembrar_dispositivo' => true,
    ]));
    post('/logout');

    // O cookie é válido — só não é desta conta. Sem o vínculo por
    // `user_id`, um cookie qualquer viraria passe livre para todo mundo.
    $this->withCookie(RememberedDeviceService::COOKIE, $cookieDoOutro)
        ->post('/login', ['email' => $usuario->email, 'password' => SENHA])
        ->assertRedirect('/entrar/dois-fatores');
});

afterEach(function () {
    RateLimiter::clear('desafio-2fa');
});
