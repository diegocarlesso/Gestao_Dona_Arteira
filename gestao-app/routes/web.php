<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

// `conta.ativa` derruba quem foi suspenso mesmo com sessão aberta;
// `senha.trocada` prende quem ainda usa senha provisória na tela de
// troca. As rotas do módulo Identity aplicam os mesmos (ver
// app/Modules/Identity/Routes/web.php).
Route::middleware(['auth', 'conta.ativa', 'senha.trocada'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
