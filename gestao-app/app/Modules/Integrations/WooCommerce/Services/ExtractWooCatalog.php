<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

use App\Modules\Integrations\WooCommerce\Client;
use App\Modules\Integrations\WooCommerce\Models\ImportBatch;
use App\Modules\Integrations\WooCommerce\Models\StgWooCategory;
use App\Modules\Integrations\WooCommerce\Models\StgWooProduct;
use Closure;
use Throwable;

/**
 * Extrai o catálogo do WooCommerce para o staging — docs/17-Migracao F2.
 *
 * Traz o dado **como ele é**, sem julgar. Produto sem SKU, título
 * duplicado, peso faltando: tudo entra. Quem decide o que fazer com isso
 * é o saneamento (F3), com aprovação humana — e essa separação é o que
 * permite reprocessar o saneamento quantas vezes for preciso sem bater na
 * API de novo.
 *
 * **Idempotente** (BR-706): a chave é o `woo_id`, então rodar duas vezes
 * atualiza em vez de duplicar. É o que torna a extração retomável depois
 * de uma queda no meio da sexta página.
 */
class ExtractWooCatalog
{
    public function __construct(private readonly Client $client) {}

    /**
     * @param  Closure(string): void|null  $progresso  recebe uma linha por página
     */
    public function produtos(bool $dryRun = false, int $apartirDaPagina = 1, ?Closure $progresso = null): ImportBatch
    {
        $lote = $this->abrirLote('products', $dryRun);

        try {
            foreach ($this->client->paginar('products', ['status' => 'any'], $apartirDaPagina) as $pagina) {
                $variacoes = [];

                foreach ($pagina['itens'] as $item) {
                    $lote->fetched++;

                    if (! $dryRun) {
                        $this->gravarProduto($item, $lote->id);
                        $lote->stored++;
                    }

                    // As variações não vêm em `/products`: o Woo as expõe
                    // por produto pai. São 39 pais e 77 variações no
                    // catálogo atual (pasta 31), e pelo ADR-0022 cada uma
                    // vira produto próprio no ERP — deixá-las de fora
                    // perderia 77 itens que a operação vende.
                    if (($item['type'] ?? null) === 'variable') {
                        $variacoes[] = (int) $item['id'];
                    }
                }

                foreach ($variacoes as $paiId) {
                    $this->extrairVariacoes($paiId, $lote, $dryRun);
                }

                $lote->last_page = $pagina['pagina'];
                $lote->save();

                $progresso?->__invoke(sprintf(
                    'página %d — %d itens acumulados',
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
     * @param  Closure(string): void|null  $progresso
     */
    public function categorias(bool $dryRun = false, ?Closure $progresso = null): ImportBatch
    {
        $lote = $this->abrirLote('categories', $dryRun);

        try {
            foreach ($this->client->paginar('products/categories') as $pagina) {
                foreach ($pagina['itens'] as $item) {
                    $lote->fetched++;

                    if (! $dryRun) {
                        StgWooCategory::query()->updateOrCreate(
                            ['woo_id' => (int) $item['id']],
                            [
                                'name' => $item['name'] ?? null,
                                'slug' => $item['slug'] ?? null,
                                // Zero no Woo é raiz; guardado como veio —
                                // traduzir para NULL é trabalho da carga.
                                'parent_woo_id' => ($item['parent'] ?? 0) ?: null,
                                'count' => $item['count'] ?? null,
                                'payload' => $item,
                                'import_batch' => $lote->id,
                                'status_triagem' => 'pending',
                            ],
                        );
                        $lote->stored++;
                    }
                }

                $lote->last_page = $pagina['pagina'];
                $lote->save();

                $progresso?->__invoke(sprintf('página %d — %d categorias', $pagina['pagina'], $lote->fetched));
            }

            $lote->concluir();
        } catch (Throwable $e) {
            $lote->falhar($e->getMessage());

            throw $e;
        }

        return $lote->refresh();
    }

    private function extrairVariacoes(int $paiId, ImportBatch $lote, bool $dryRun): void
    {
        foreach ($this->client->paginar("products/{$paiId}/variations") as $pagina) {
            foreach ($pagina['itens'] as $variacao) {
                $lote->fetched++;

                if ($dryRun) {
                    continue;
                }

                // `type` vem vazio no endpoint de variações — o Woo o
                // considera implícito. Preenchemos, senão a triagem não
                // consegue distinguir uma variação de um produto simples
                // olhando só para o staging.
                $variacao['type'] = 'variation';
                $variacao['parent_id'] = $paiId;

                $this->gravarProduto($variacao, $lote->id);
                $lote->stored++;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function gravarProduto(array $item, int $loteId): void
    {
        StgWooProduct::query()->updateOrCreate(
            ['woo_id' => (int) $item['id']],
            [
                'type' => $item['type'] ?? null,
                'parent_woo_id' => ($item['parent_id'] ?? 0) ?: null,
                'name' => $item['name'] ?? null,
                // Vem vazio em 716 de 716 (pasta 31 §3.1). A string vazia
                // é gravada como NULL para a triagem poder contar
                // "quantos sem SKU" com um `whereNull` honesto.
                'sku' => ($item['sku'] ?? '') !== '' ? $item['sku'] : null,
                'status' => $item['status'] ?? null,
                'regular_price' => $item['regular_price'] ?? null,
                'weight' => ($item['weight'] ?? '') !== '' ? $item['weight'] : null,
                'woo_modified_at' => $item['date_modified_gmt'] ?? null,
                'payload' => $item,
                'import_batch' => $loteId,
                'status_triagem' => 'pending',
            ],
        );
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
}
