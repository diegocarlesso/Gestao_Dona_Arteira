<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\ForcedPasswordController;
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
        Route::get('usuarios', [UserController::class, 'index'])->name('identity.users.index');
        Route::get('usuarios/novo', [UserController::class, 'create'])->name('identity.users.create');
        Route::post('usuarios', [UserController::class, 'store'])->name('identity.users.store');

        // {user} resolve por `public_id` — o getRouteKeyName do model.
        // O id sequencial não aparece em URL (convenção da pasta 04).
        Route::get('usuarios/{user}', [UserController::class, 'edit'])->name('identity.users.edit');
        Route::put('usuarios/{user}', [UserController::class, 'update'])->name('identity.users.update');
    });
});
