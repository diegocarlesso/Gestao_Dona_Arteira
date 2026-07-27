<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce;

use App\Modules\Integrations\WooCommerce\Exceptions\IntegracaoWooIndisponivel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fala com a API REST do WooCommerce — docs/15-Integracoes §3.
 *
 * **Nunca banco a banco** (BR-701). O ADR-0010 descartou explicitamente
 * ler o esquema interno do Woo: `postmeta` é EAV, não é documentado como
 * contrato e muda entre versões e plugins. A API REST é a fronteira
 * suportada.
 *
 * Responsabilidade única: autenticar, paginar e devolver array. Não sabe
 * o que é produto — traduzir é dos Adapters.
 */
class Client
{
    private const VERSAO_API = 'wc/v3';

    public function isEnabled(): bool
    {
        return (bool) config('integrations.woocommerce.enabled');
    }

    /**
     * Uma página de um recurso.
     *
     * @param  array<string, scalar>  $parametros
     * @return array<int, array<string, mixed>>
     */
    public function get(string $recurso, array $parametros = []): array
    {
        $resposta = $this->request()->get($this->url($recurso), $parametros);

        if ($resposta->failed()) {
            throw IntegracaoWooIndisponivel::resposta($recurso, $resposta->status(), $resposta->body());
        }

        /** @var array<int, array<string, mixed>> $dados */
        $dados = $resposta->json() ?? [];

        return $dados;
    }

    /**
     * Percorre todas as páginas de um recurso, uma de cada vez.
     *
     * Generator, não array: o catálogo tem 716 produtos com descrição em
     * HTML e listas de imagens — carregar tudo em memória de uma vez é o
     * tipo de coisa que funciona no desenvolvimento e estoura no plano
     * compartilhado, onde `memory_limit` é por processo.
     *
     * @param  array<string, scalar>  $parametros
     * @return \Generator<int, array{pagina: int, itens: array<int, array<string, mixed>>}>
     */
    public function paginar(string $recurso, array $parametros = [], int $apartirDaPagina = 1): \Generator
    {
        $porPagina = (int) config('integrations.woocommerce.per_page', 50);
        $pagina = max(1, $apartirDaPagina);
        $limite = (int) config('integrations.woocommerce.max_pages', 1000);

        while (true) {
            // Trava contra laço infinito. A parada normal é a página vir
            // incompleta — mas isso confia no servidor se comportar, e do
            // outro lado há um WordPress com plugins que ninguém auditou.
            // Um endpoint que sempre devolve página cheia giraria até
            // estourar a memória; com 716 produtos, mil páginas é folga de
            // sobra e ainda assim é finito.
            if ($pagina - $apartirDaPagina >= $limite) {
                throw IntegracaoWooIndisponivel::paginacaoSemFim($recurso, $limite);
            }

            $itens = $this->get($recurso, $parametros + [
                'page' => $pagina,
                'per_page' => $porPagina,
            ]);

            if ($itens === []) {
                return;
            }

            yield ['pagina' => $pagina, 'itens' => $itens];

            // Página incompleta é a última — poupa uma requisição que
            // voltaria vazia. O Woo devolve o total em cabeçalho, mas
            // depender dele exigiria confiar num plugin não alterar isso.
            if (count($itens) < $porPagina) {
                return;
            }

            $pagina++;
        }
    }

    private function request(): PendingRequest
    {
        $this->garantirConfiguracao();

        return Http::withBasicAuth(
            (string) config('integrations.woocommerce.key'),
            (string) config('integrations.woocommerce.secret'),
        )
            ->timeout((int) config('integrations.woocommerce.timeout', 30))
            // Retry de **transporte** apenas: rede instável e 5xx do
            // servidor do site. Erro de autenticação ou de parâmetro não
            // melhora tentando de novo, e insistir só atrasa o diagnóstico.
            ->retry(3, 1000, throw: false)
            ->acceptJson();
    }

    private function url(string $recurso): string
    {
        $base = rtrim((string) config('integrations.woocommerce.url'), '/');

        return "{$base}/wp-json/".self::VERSAO_API."/{$recurso}";
    }

    private function garantirConfiguracao(): void
    {
        if (! $this->isEnabled()) {
            throw IntegracaoWooIndisponivel::desligada();
        }

        foreach (['url', 'key', 'secret'] as $chave) {
            if (blank(config("integrations.woocommerce.{$chave}"))) {
                throw IntegracaoWooIndisponivel::semCredencial($chave);
            }
        }
    }

    /**
     * Confere se as credenciais funcionam, sem trazer dado nenhum.
     *
     * Existe para o comando poder falhar em um segundo, com mensagem
     * clara, em vez de descobrir no meio da terceira página que a chave
     * está errada.
     */
    public function testarConexao(): Response
    {
        return $this->request()->get($this->url('products'), ['per_page' => 1]);
    }
}
