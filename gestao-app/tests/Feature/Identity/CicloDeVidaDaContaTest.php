<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;

/*
|--------------------------------------------------------------------------
| Ciclo de vida da conta — docs/18-Usuarios/README.md §3
|--------------------------------------------------------------------------
*/

it('só permite autenticação de conta ativa', function () {
    expect(User::factory()->create()->canAuthenticate())->toBeTrue();
    expect(User::factory()->invited()->create()->canAuthenticate())->toBeFalse();
    expect(User::factory()->suspended()->create()->canAuthenticate())->toBeFalse();
    expect(User::factory()->disabled()->create()->canAuthenticate())->toBeFalse();
});

it('trata o desligamento como estado terminal', function () {
    expect(UserStatus::Disabled->allowedTransitions())->toBeEmpty();
    expect(UserStatus::Disabled->canTransitionTo(UserStatus::Active))->toBeFalse();
});

it('permite suspender e reativar', function () {
    expect(UserStatus::Active->canTransitionTo(UserStatus::Suspended))->toBeTrue();
    expect(UserStatus::Suspended->canTransitionTo(UserStatus::Active))->toBeTrue();
});

it('não deixa uma conta convidada voltar a ser convidada', function () {
    expect(UserStatus::Active->canTransitionTo(UserStatus::Invited))->toBeFalse();
    expect(UserStatus::Suspended->canTransitionTo(UserStatus::Invited))->toBeFalse();
});

it('gera public_id ULID em toda criação, por qualquer caminho', function () {
    $porFactory = User::factory()->create();
    $porModel = User::query()->create([
        'name' => 'Direto pelo model',
        'email' => 'direto@exemplo.test',
        'password' => 'uma-senha-bem-longa-aqui',
    ]);

    foreach ([$porFactory, $porModel] as $usuario) {
        expect($usuario->public_id)->toHaveLength(26);
        expect(Str::isUlid($usuario->public_id))->toBeTrue();
    }

    expect($porFactory->public_id)->not->toBe($porModel->public_id);
});

it('resolve a rota pelo public_id, não pelo id sequencial', function () {
    expect(User::factory()->create()->getRouteKeyName())->toBe('public_id');
});

it('esconde senha e segredos ao serializar', function () {
    $usuario = User::factory()->create();
    $usuario->two_factor_secret = 'SEGREDO-TOTP';
    $usuario->save();

    $serializado = $usuario->fresh()->toArray();

    expect($serializado)->not->toHaveKey('password');
    expect($serializado)->not->toHaveKey('remember_token');
    expect($serializado)->not->toHaveKey('two_factor_secret');
    expect($serializado)->not->toHaveKey('two_factor_recovery_codes');
});

it('não aceita segredo de 2FA por mass assignment', function () {
    // Se o segredo fosse fillable, um campo escondido num formulário de
    // perfil bastaria para a pessoa plantar o próprio TOTP na conta de
    // outra. Ele é atribuído só pelo serviço que ativa o 2FA.
    $usuario = User::factory()->create();

    $usuario->update([
        'two_factor_secret' => 'SEGREDO-INJETADO',
        'two_factor_confirmed_at' => now(),
        'must_change_password' => false,
    ]);

    expect($usuario->fresh()->two_factor_secret)->toBeNull();
    expect($usuario->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('cifra o segredo de 2FA no banco', function () {
    // Pelo caminho real (a Action do Fortify), não escrevendo a coluna à
    // mão: desde o ADR-0021 quem cifra é o Fortify, não um cast do model,
    // e um teste que atribuísse a propriedade direto provaria o contrário
    // do que a aplicação faz.
    $usuario = User::factory()->create();

    app(EnableTwoFactorAuthentication::class)($usuario);
    $usuario->refresh();

    $noBanco = DB::table('users')->where('id', $usuario->id)->value('two_factor_secret');
    $emClaro = Fortify::currentEncrypter()->decrypt($usuario->two_factor_secret);

    expect($noBanco)->not->toBe($emClaro)
        ->and($emClaro)->not->toBeEmpty()
        // O QR precisa do segredo legível — se a cifragem tivesse dois
        // donos (cast + Fortify), decifrar uma vez devolveria lixo e este
        // `otpauth://` não se formaria.
        ->and($usuario->twoFactorQrCodeUrl())->toContain('otpauth://');
});

it('gera oito códigos de recuperação ao ativar o 2FA', function () {
    $usuario = User::factory()->create();

    app(EnableTwoFactorAuthentication::class)($usuario);

    expect($usuario->refresh()->recoveryCodes())->toHaveCount(8);
});

it('só considera 2FA ativo depois de confirmado', function () {
    $usuario = User::factory()->create();
    expect($usuario->hasTwoFactorEnabled())->toBeFalse();

    $usuario->two_factor_secret = 'SEGREDO-TOTP';
    $usuario->save();
    expect($usuario->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $usuario->two_factor_confirmed_at = now();
    $usuario->save();
    expect($usuario->fresh()->hasTwoFactorEnabled())->toBeTrue();
});
