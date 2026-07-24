<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'conta.ativa', 'senha.trocada'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Não há `profile.destroy`. O starter kit traz autoexclusão de conta;
    // ela foi removida em 2026-07-24 porque contraria o ciclo de vida da
    // pasta 18 §3, que não tem estado "excluído" — conta se encerra em
    // `disabled`, por um admin. Apagar a própria conta levaria junto a
    // trilha de segurança da pasta 26, e é justamente o registro de que
    // alguém existiu que a trilha não pode perder. Há teste provando que
    // a rota não voltou.

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');
});
