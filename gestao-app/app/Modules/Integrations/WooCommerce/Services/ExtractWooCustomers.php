<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

use App\Modules\Integrations\WooCommerce\Client;
use App\Modules\Integrations\WooCommerce\Models\ImportBatch;
use App\Modules\Integrations\WooCommerce\Models\StgWooCustomer;
use Closure;
use Throwable;

/**
 * Extrai os clientes do WooCommerce para o staging — docs/17-Migracao F2.
 *
 * Mesmo princípio da extração do catálogo: traz o dado **como ele é, sem
 * julgar**. Cadastro sem compra, CPF que não fecha, endereço pela metade —
 * tudo entra. Quem decide o que migra é a triagem (F3), e é lá que a
 * rejeição fica registrada e visível.
 *
 * A separação importa mais aqui do que no catálogo: filtrar na extração
 * ("só quem comprou") economizaria linhas de staging e custaria a
 * **prestação de contas** — ninguém conseguiria dizer, depois, quantos
 * ficaram de fora e por quê.
 *
 * **Idempotente** (BR-706): a chave é o `woo_id`, então rodar duas vezes
 * atualiza em vez de duplicar.
 *
 * ## Uma fronteira que a extração respeita
 *
 * O endpoint `/customers` do Woo devolve, por padrão, só quem tem papel
 * `customer`. **Não pedimos `role=all`** de propósito: os 2 outros
 * usuários do site são administradores do WordPress (pasta 31 §2), não
 * clientes da loja. Trazê-los seria copiar dado pessoal de quem nunca
 * comprou para dentro do ERP — exatamente o que a decisão de escopo de
 * 2026-08-10 evita.
 */
class ExtractWooCustomers
{
    private const NAMESPACE_ANALYTICS = 'wc-analytics';

    public function __construct(private readonly Client $client) {}

    /**
     * @param  Closure(string): void|null  $progresso  recebe uma linha por página
     */
    public function clientes(bool $dryRun = false, int $apartirDaPagina = 1, ?Closure $progresso = null): ImportBatch
    {
        $lote = $this->abrirLote('customers', $dryRun);
        $comprasPorUsuario = $this->comprasPorUsuario();

        try {
            foreach ($this->client->paginar('customers', [], $apartirDaPagina) as $pagina) {
                foreach ($pagina['itens'] as $item) {
                    $lote->fetched++;

                    if (! $dryRun) {
                        $this->gravar($item, $lote->id, $comprasPorUsuario);
                        $lote->stored++;
                    }
                }

                $lote->last_page = $pagina['pagina'];
                $lote->save();

                $progresso?->__invoke(sprintf(
                    'página %d — %d cadastros acumulados',
                    $pagina['pagina'],
                    $lote->fetched,
                ));
            }

            $lote->concluir();
        } catch (Throwable $e) {
            // O lote falho guarda `last_page`: retomar a partir dela não
            // repete o que já entrou (o upsert cuida disso), mas evita
            // buscar de novo o que já foi baixado.
            $lote->falhar($e->getMessage());

            throw $e;
        }

        return $lote->refresh();
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, int>  $comprasPorUsuario  user_id => pedidos (ver comprasPorUsuario())
     */
    private function gravar(array $item, int $loteId, array $comprasPorUsuario): void
    {
        StgWooCustomer::query()->updateOrCreate(
            ['woo_id' => (int) $item['id']],
            [
                // Minúsculas e sem espaço nas pontas já na entrada: o
                // e-mail é a chave de dedupe nº 1 (de-para da pasta 16), e
                // `Ana@X.com` × `ana@x.com` são a mesma pessoa. Guardar como
                // veio faria a triagem procurar duplicata com uma
                // comparação que o SQLite dos testes trata de um jeito e o
                // MariaDB de produção de outro.
                'email' => $this->texto($item['email'] ?? null, minusculo: true),
                'first_name' => $this->texto($item['first_name'] ?? null),
                'last_name' => $this->texto($item['last_name'] ?? null),

                // Nulo quando o cliente não aparece no relatório de
                // analytics (ver comprasPorUsuario()) — é o que separa
                // "nunca comprou" (0, rejeita) de "não deu para saber"
                // (nulo, fica pendente). `/wc/v3/customers` nunca trouxe
                // esse campo, apesar do que a versão anterior deste código
                // supunha — confirmado contra a API de produção.
                'orders_count' => $comprasPorUsuario[(int) $item['id']] ?? null,

                'woo_modified_at' => $item['date_modified_gmt'] ?? null,
                'payload' => $item,
                'import_batch' => $loteId,
                'status_triagem' => 'pending',
            ],
        );
    }

    private function texto(mixed $valor, bool $minusculo = false): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        if ($valor === '') {
            return null;
        }

        return $minusculo ? mb_strtolower($valor) : $valor;
    }

    private function abrirLote(string $entidade, bool $dryRun): ImportBatch
    {
        return ImportBatch::query()->create([
            'source' => 'woocommerce',
            'entity' => $entidade,
            'status' => 'running',
            'dry_run' => $dryRun,
            'started_at' => now(),
        ]);
    }

    /**
     * Quantos pedidos cada cliente já fez, pelo `user_id` do WordPress.
     *
     * `/wc/v3/customers` nunca devolveu `orders_count` — confirmado contra a
     * API de produção em 2026-08-11, não é suposição. O dado real vem do
     * relatório de clientes do WooCommerce Analytics (`wc-analytics`, no core
     * desde a 4.0), que soma os pedidos por `user_id`.
     *
     * Cliente sem sync nesse relatório (~6 dos 198 medidos) some do mapa — e
     * isso é "não dá para saber", não "nunca comprou": `gravar()` trata os
     * dois jeitos diferente de propósito.
     *
     * @return array<int, int> user_id => quantidade de pedidos
     */
    private function comprasPorUsuario(): array
    {
        $mapa = [];

        foreach ($this->client->paginar('reports/customers', [], versaoApi: self::NAMESPACE_ANALYTICS) as $pagina) {
            foreach ($pagina['itens'] as $item) {
                if (! isset($item['user_id'])) {
                    continue;
                }

                $mapa[(int) $item['user_id']] = (int) ($item['orders_count'] ?? 0);
            }
        }

        return $mapa;
    }
}
