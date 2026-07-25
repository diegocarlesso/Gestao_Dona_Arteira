<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;

/*
|--------------------------------------------------------------------------
| Não existe cadastro público — pasta 18 §3.2
|--------------------------------------------------------------------------
|
| Substitui o `RegistrationTest` do starter kit, que provava justamente o
| contrário. Conta neste ERP nasce por convite de quem tem `users.manage`,
| com papel atribuído na criação.
|
| Até 2026-07-25 a rota respondia 200 em produção: qualquer pessoa criava
| conta e, como `status` tem default `active`, essa conta entrava no
| sistema. Sem papel não alcançava nada (BR-801), mas ocupava a tabela de
| usuários e aparecia na listagem como se alguém a tivesse convidado.
|
*/

it('não expõe tela de cadastro', function () {
    $this->get('/register')->assertNotFound();
});

it('não aceita criação de conta por POST', function () {
    $this->post('/register', [
        'name' => 'Invasor',
        'email' => 'invasor@exemplo.test',
        'password' => 'senha-longa-de-teste-123',
        'password_confirmation' => 'senha-longa-de-teste-123',
    ])->assertNotFound();

    expect(User::query()->where('email', 'invasor@exemplo.test')->exists())->toBeFalse();
});

it('não tem rota chamada register registrada', function () {
    // A ausência da rota nomeada é o que impede um link órfão no frontend
    // apontar para ela — `route('register')` estouraria no build.
    expect(app('router')->has('register'))->toBeFalse();
});

it('leva a raiz direto para o sistema, sem página de boas-vindas', function () {
    $this->get('/')->assertRedirect('/dashboard');
});
