<?php

declare(strict_types=1);

use Laravel\Fortify\FortifyServiceProvider;
use Laravel\Passkeys\PasskeysServiceProvider;

/*
|--------------------------------------------------------------------------
| Fortify entra pelas Actions, nunca pelo HTTP — ADR-0021
|--------------------------------------------------------------------------
|
| O ADR-0021 autoriza usar quatro classes do `laravel/fortify` e mais nada.
| O pacote, porém, declara o próprio provider em `extra.laravel.providers`
| e seria **auto-registrado** pelo package discovery — trazendo junto rotas
| de login, registro, reset de senha e 2FA que duplicariam as nossas.
|
| O bloqueio é o `extra.laravel.dont-discover` do composer.json, que é
| justamente o tipo de configuração que um `composer update` malfeito ou um
| merge desatento desfaz sem ninguém notar. Estes testes são o alarme: se o
| provider voltar, o CI reprova antes de a aplicação ganhar uma segunda
| porta de entrada.
|
*/

it('não registra o FortifyServiceProvider', function () {
    expect(app()->getLoadedProviders())
        ->not->toHaveKey(FortifyServiceProvider::class);
});

it('não registra o PasskeysServiceProvider', function () {
    // Veio como dependência obrigatória do Fortify 1.37, não por escolha
    // nossa. WebAuthn/passkeys é fase 7 (pasta 18 §7), e até lá o pacote
    // fica inerte.
    expect(app()->getLoadedProviders())
        ->not->toHaveKey(PasskeysServiceProvider::class);
});

it('não expõe nenhuma rota vinda do Fortify', function () {
    $doFortify = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($rota): string => (string) $rota->getActionName())
        ->filter(fn (string $acao): bool => str_contains($acao, 'Laravel\\Fortify')
            || str_contains($acao, 'Laravel\\Passkeys'))
        ->values()
        ->all();

    expect($doFortify)->toBe([]);
});

it('mantém os dois pacotes fora do package discovery', function () {
    /** @var array{extra?: array{laravel?: array{'dont-discover'?: list<string>}}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    expect($composer['extra']['laravel']['dont-discover'] ?? [])
        ->toContain('laravel/fortify')
        ->toContain('laravel/passkeys');
});
