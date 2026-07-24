<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Rules\PasswordPolicy;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Política de senha — pasta 18 §3
|--------------------------------------------------------------------------
|
| A regra é uma só: mínimo de 12 caracteres e recusa de senha que já
| apareceu em vazamento. Ela valia na troca obrigatória e NÃO valia na
| tela de configurações, que usava o `Password::defaults()` do starter kit
| — 8 caracteres, sem checagem. Estes testes existem para que os dois
| caminhos não voltem a divergir.
|
| O `uncompromised` consulta o HaveIBeenPwned. A consulta é falsificada
| aqui — sem isso, o primeiro teste levava 25 segundos e a suíte passava a
| depender de a internet estar de pé no CI. O helper `respostaDoHibp()`
| monta a resposta no formato real da API, então o que se testa continua
| sendo o comportamento da regra, não o do `Http::fake`.
|
*/

beforeEach(function () {
    // Nenhuma requisição real sai daqui. `preventStrayRequests` faz um
    // endpoint esquecido estourar em vez de vazar para a rede em silêncio.
    Http::preventStrayRequests();

    // Um único stub, consultando a lista que cada teste preenche. Dois
    // `Http::fake` para a mesma URL não se sobrepõem: o Laravel usa o
    // **primeiro** que casa, então um fake declarado dentro do teste
    // seria ignorado em favor deste.
    $this->senhasVazadas = [];

    Http::fake(['api.pwnedpasswords.com/*' => function () {
        // A API recebe os 5 primeiros caracteres do SHA-1 e devolve as
        // linhas `SUFIXO:CONTAGEM` que começam com eles. O verificador do
        // Laravel compara prefixo+sufixo com o hash inteiro, então incluir
        // sufixos de outros prefixos aqui é inofensivo.
        $linhas = array_map(
            static fn (string $senha): string => substr(strtoupper(sha1($senha)), 5).':42',
            $this->senhasVazadas,
        );

        return Http::response(implode("\r\n", $linhas), 200);
    }]);
});

it('recusa senha curta nas duas telas de troca', function (string $rota, bool $trocaObrigatoria) {
    $usuario = User::factory()->create([
        'password' => bcrypt('senha-atual-longa'),
        'must_change_password' => $trocaObrigatoria,
    ]);

    $resposta = $this->actingAs($usuario)->put($rota, [
        'current_password' => 'senha-atual-longa',
        'password' => 'Curta123!',
        'password_confirmation' => 'Curta123!',
    ]);

    $resposta->assertSessionHasErrors('password');
})->with([
    // `must_change_password` precisa acompanhar a rota: com a flag ligada
    // o middleware `senha.trocada` sequestra qualquer outra requisição
    // para a tela de troca obrigatória, e o teste de configurações nunca
    // chegaria ao controller.
    'troca obrigatória' => ['/trocar-senha', true],
    'configurações' => ['/settings/password', false],
]);

it('aceita senha que atende à política nas duas telas', function (string $rota, bool $trocaObrigatoria) {
    $usuario = User::factory()->create([
        'password' => bcrypt('senha-atual-longa'),
        'must_change_password' => $trocaObrigatoria,
    ]);

    // Frase longa e improvável de constar em vazamento — o
    // `uncompromised` é consultado de verdade, então precisa passar.
    $nova = 'gesso-artesanal-dona-arteira-2026';

    $this->actingAs($usuario)->put($rota, [
        'current_password' => 'senha-atual-longa',
        'password' => $nova,
        'password_confirmation' => $nova,
    ])->assertSessionHasNoErrors();

    expect(Hash::check($nova, $usuario->fresh()->password))->toBeTrue();
})->with([
    // `must_change_password` precisa acompanhar a rota: com a flag ligada
    // o middleware `senha.trocada` sequestra qualquer outra requisição
    // para a tela de troca obrigatória, e o teste de configurações nunca
    // chegaria ao controller.
    'troca obrigatória' => ['/trocar-senha', true],
    'configurações' => ['/settings/password', false],
]);

it('preenche password_changed_at nas duas telas', function (string $rota, bool $trocaObrigatoria) {
    $usuario = User::factory()->create([
        'password' => bcrypt('senha-atual-longa'),
        'must_change_password' => $trocaObrigatoria,
        'password_changed_at' => null,
    ]);

    $nova = 'gesso-artesanal-dona-arteira-2026';

    $this->actingAs($usuario)->put($rota, [
        'current_password' => 'senha-atual-longa',
        'password' => $nova,
        'password_confirmation' => $nova,
    ]);

    // A coluna responde "há quanto tempo esta senha é a mesma". Ficava
    // mentindo para quem trocasse pela tela de configurações.
    expect($usuario->fresh()->password_changed_at)->not->toBeNull();
})->with([
    // `must_change_password` precisa acompanhar a rota: com a flag ligada
    // o middleware `senha.trocada` sequestra qualquer outra requisição
    // para a tela de troca obrigatória, e o teste de configurações nunca
    // chegaria ao controller.
    'troca obrigatória' => ['/trocar-senha', true],
    'configurações' => ['/settings/password', false],
]);

it('registra a troca na trilha de segurança nas duas telas', function (string $rota, bool $trocaObrigatoria) {
    $usuario = User::factory()->create([
        'password' => bcrypt('senha-atual-longa'),
        'must_change_password' => $trocaObrigatoria,
    ]);

    $nova = 'gesso-artesanal-dona-arteira-2026';

    $this->actingAs($usuario)->put($rota, [
        'current_password' => 'senha-atual-longa',
        'password' => $nova,
        'password_confirmation' => $nova,
    ]);

    // `password` está em $auditExclude e deve continuar: sem este evento a
    // troca de senha não deixaria rastro em lugar nenhum.
    expect(SecurityEvent::query()->ofType(SecurityEventType::PasswordChanged)->count())->toBe(1);
})->with([
    // `must_change_password` precisa acompanhar a rota: com a flag ligada
    // o middleware `senha.trocada` sequestra qualquer outra requisição
    // para a tela de troca obrigatória, e o teste de configurações nunca
    // chegaria ao controller.
    'troca obrigatória' => ['/trocar-senha', true],
    'configurações' => ['/settings/password', false],
]);

it('recusa senha longa que já apareceu em vazamento, nas duas telas', function (string $rota, bool $trocaObrigatoria) {
    // Longa o bastante para passar no mínimo de 12 — o que a reprova é
    // exclusivamente o `uncompromised`. Sem este teste, alguém poderia
    // remover a verificação e todos os outros continuariam verdes.
    $vazada = 'senha-que-vazou-em-2019';

    $this->senhasVazadas = [$vazada];

    $usuario = User::factory()->create([
        'password' => bcrypt('senha-atual-longa'),
        'must_change_password' => $trocaObrigatoria,
    ]);

    $this->actingAs($usuario)->put($rota, [
        'current_password' => 'senha-atual-longa',
        'password' => $vazada,
        'password_confirmation' => $vazada,
    ])->assertSessionHasErrors('password');
})->with([
    'troca obrigatória' => ['/trocar-senha', true],
    'configurações' => ['/settings/password', false],
]);

it('mantém o mínimo de caracteres no valor que a documentação afirma', function () {
    // A pasta 18 §3 diz 12. Baixar isso tem de ser decisão consciente, com
    // a documentação junto — não efeito colateral de um refactor.
    expect(PasswordPolicy::MINIMO_DE_CARACTERES)->toBe(12);
});
