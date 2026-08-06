<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
| A raiz leva direto ao sistema. Era a página de boas-vindas do starter
| kit do Laravel — com links para a documentação do framework e para o
| Laracasts, numa aplicação que atende uma empresa. Quem chega aqui quer
| entrar, e é isso que acontece: autenticado vai ao painel, anônimo cai
| no login pelo middleware.
*/
Route::redirect('/', '/dashboard')->name('home');

// `conta.ativa` derruba quem foi suspenso mesmo com sessão aberta;
// `senha.trocada` prende quem ainda usa senha provisória na tela de
// troca; `2fa.confirmado` prende quem tem papel que exige segundo fator
// (BR-804) e ainda não o configurou. As rotas dos módulos aplicam os
// mesmos três, na mesma ordem.
//
// O `2fa.confirmado` faltava aqui até 2026-07-25: o painel era a única
// tela autenticada sem ele, então um admin sem 2FA entrava, via o painel
// e só era barrado ao tentar qualquer outra coisa. A obrigatoriedade
// valia em todo lugar menos na porta de entrada.
Route::middleware(['auth', 'conta.ativa', 'senha.trocada', '2fa.confirmado'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
