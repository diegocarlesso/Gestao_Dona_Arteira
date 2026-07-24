<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Senhas que o HaveIBeenPwned deve reportar como vazadas neste teste.
     *
     * Vazia por padrão: nenhuma senha é considerada comprometida, a menos
     * que o teste diga o contrário.
     *
     * @var list<string>
     */
    protected array $senhasVazadas = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fingirHaveIBeenPwned();
    }

    /**
     * A política de senha usa `uncompromised()`, que consulta o
     * HaveIBeenPwned por HTTP. Sem este fake a suíte inteira depende de a
     * internet estar de pé — e pior: **falha de forma assimétrica**.
     *
     * Foi assim que apareceu. O verificador do Laravel trata requisição
     * malsucedida como "senha não vazada", então o teste do starter kit
     * que troca a senha por `new-password` passava na máquina de
     * desenvolvimento, onde a chamada morria em silêncio, e reprovava no
     * CI, onde ela funcionava e encontrava a senha na lista. Verde local
     * não significava nada.
     *
     * `preventStrayRequests` fecha o resto: qualquer chamada externa que
     * um teste faça sem querer estoura, em vez de vazar para a rede.
     */
    private function fingirHaveIBeenPwned(): void
    {
        Http::preventStrayRequests();

        Http::fake(['api.pwnedpasswords.com/*' => function () {
            // A API recebe os 5 primeiros caracteres do SHA-1 e devolve as
            // linhas `SUFIXO:CONTAGEM` que começam com eles. O verificador
            // compara prefixo+sufixo com o hash inteiro, então incluir
            // sufixos de outros prefixos aqui é inofensivo.
            $linhas = array_map(
                static fn (string $senha): string => substr(strtoupper(sha1($senha)), 5).':42',
                $this->senhasVazadas,
            );

            return Http::response(implode("\r\n", $linhas), 200);
        }]);
    }
}
