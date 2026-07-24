<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\ForcedPasswordController;
use App\Modules\Identity\Http\Controllers\TwoFactorChallengeController;
use App\Modules\Identity\Http\Controllers\TwoFactorController;
use App\Modules\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas do módulo Identity
|--------------------------------------------------------------------------
|
| Carregadas pelo IdentityServiceProvider. Cada módulo traz as suas
| (pasta 05 §2), em vez de tudo inchar routes/web.php.
|
*/

Route::middleware(['web', 'auth', 'conta.ativa'])->group(function () {
    // Fora do middleware `senha.trocada`: é justamente para cá que ele
    // redireciona. Dentro, o redirecionamento seria circular.
    Route::get('trocar-senha', [ForcedPasswordController::class, 'edit'])
        ->name('identity.password.forced.edit');
    Route::put('trocar-senha', [ForcedPasswordController::class, 'update'])
        ->name('identity.password.forced.update');

    Route::middleware('senha.trocada')->group(function () {
        // Fora do `2fa.confirmado` pelo mesmo motivo da troca de senha: é
        // para cá que ele redireciona. A ordem da cadeia é
        // conta.ativa → senha.trocada → 2fa.confirmado (ADR-0021) — não
        // se configura 2FA ainda com a senha provisória em uso.
        Route::get('dois-fatores', [TwoFactorController::class, 'edit'])->name('identity.2fa.edit');
        Route::post('dois-fatores', [TwoFactorController::class, 'store'])->name('identity.2fa.store');
        Route::post('dois-fatores/confirmar', [TwoFactorController::class, 'confirm'])->name('identity.2fa.confirm');
        Route::delete('dois-fatores', [TwoFactorController::class, 'destroy'])->name('identity.2fa.destroy');

        Route::middleware('2fa.confirmado')->group(function () {
            Route::post('dois-fatores/codigos-de-recuperacao', [TwoFactorController::class, 'recoveryCodes'])
                ->name('identity.2fa.recovery-codes');
            Route::delete('dois-fatores/dispositivos', [TwoFactorController::class, 'forgetDevices'])
                ->name('identity.2fa.forget-devices');

            Route::get('usuarios', [UserController::class, 'index'])->name('identity.users.index');
            Route::get('usuarios/novo', [UserController::class, 'create'])->name('identity.users.create');
            Route::post('usuarios', [UserController::class, 'store'])->name('identity.users.store');

            // {user} resolve por `public_id` — o getRouteKeyName do model.
            // O id sequencial não aparece em URL (convenção da pasta 04).
            Route::get('usuarios/{user}', [UserController::class, 'edit'])->name('identity.users.edit');
            Route::put('usuarios/{user}', [UserController::class, 'update'])->name('identity.users.update');
        });
    });
});

/*
| O desafio do segundo fator no login (ADR-0021).
|
| Fora de `auth`: quem chega aqui acertou a senha e **não** está
| autenticado — é esse o ponto. A identidade vem da sessão do desafio, e o
| próprio controller devolve ao login quando ela não existe ou expirou.
*/
Route::middleware('web')->group(function () {
    // `entrar/...` e não `dois-fatores/...`: o caminho de setup já ocupa
    // aquele prefixo e exige autenticação. Duas rotas POST na mesma URI
    // com middlewares opostos seria a receita para uma delas nunca rodar.
    Route::get('entrar/dois-fatores', [TwoFactorChallengeController::class, 'create'])
        ->name('identity.2fa.challenge');
    Route::post('entrar/dois-fatores', [TwoFactorChallengeController::class, 'store'])
        ->name('identity.2fa.challenge.store');
});
