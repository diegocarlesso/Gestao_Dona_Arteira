<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

use App\Modules\Catalog\Services\GenerateSku;
use App\Modules\Integrations\WooCommerce\Enums\DestinoDaTriagem;
use App\Modules\Integrations\WooCommerce\Models\StgWooProduct;
use Illuminate\Support\Facades\DB;

/**
 * Saneamento do catálogo — docs/17-Migracao F3.
 *
 * Decide o destino de cada linha do staging e propõe o SKU que ela
 * receberá. **Não carrega nada**: a carga é a F4, e só depois de alguém
 * aprovar — a BR-002 torna o código imutável, então errar aqui é errar
 * para sempre.
 *
 * ## A classificação não é uniforme por tipo do Woo
 *
 * Foi o que os dados reais mostraram, contrariando o que a documentação
 * supunha. Um `variable` pode virar produto **ou** ser descartado:
 *
 * - `simple` → produto, sempre.
 * - `variation` **com** atributo → produto. São 56, todas de composição
 *   de kit (`pa_peca-kit-1`), com preço e peso próprios — itens
 *   genuinamente distintos, não a mesma peça noutra cor.
 * - `variation` **sem** atributo → descartada. São 21, todas de anúncios
 *   que declaram cor como eixo e nunca materializaram as variações.
 * - `variable` cujas variações são reais → invólucro, descartado.
 * - `variable` cuja única variação é vazia → **o pai vira o produto**,
 *   porque é ele que carrega o nome e o anúncio; a variação só empresta
 *   o preço.
 *
 * A última regra existe por decisão do dono em 2026-07-27: nos anúncios
 * "várias cores", **um anúncio vira um produto**, com a cor como texto.
 * Expandir cada cor em produto próprio só faria sentido se a operação
 * mantivesse peças pintadas em estoque, e ela não mantém.
 */
class TriageWooProducts
{
    public function __construct(private readonly GenerateSku $sku) {}

    /**
     * @return array<string, int> contagem por destino
     */
    public function executar(): array
    {
        DB::transaction(function (): void {
            $this->classificar();
            $this->proporSkus();
        });

        return StgWooProduct::query()
            ->selectRaw('status_triagem, count(*) as total')
            ->groupBy('status_triagem')
            ->pluck('total', 'status_triagem')
            ->all();
    }

    private function classificar(): void
    {
        // Quais pais têm variação de verdade — decidido de uma vez, para
        // não consultar o banco uma vez por linha em 793 linhas.
        $paisComVariacaoReal = StgWooProduct::query()
            ->where('type', 'variation')
            ->get()
            ->filter(fn (StgWooProduct $v): bool => $this->temAtributo($v))
            ->pluck('parent_woo_id')
            ->unique()
            ->flip();

        foreach (StgWooProduct::query()->cursor() as $linha) {
            [$destino, $motivo] = match (true) {
                $linha->type === 'simple' => [
                    DestinoDaTriagem::Produto,
                    'produto simples',
                ],

                $linha->type === 'variation' && $this->temAtributo($linha) => [
                    DestinoDaTriagem::Produto,
                    'variação com composição própria',
                ],

                $linha->type === 'variation' => [
                    DestinoDaTriagem::VariacaoVazia,
                    'variação sem atributo — quem vira produto é o anúncio',
                ],

                $linha->type === 'variable' && $paisComVariacaoReal->has($linha->woo_id) => [
                    DestinoDaTriagem::Involucro,
                    'agrupa variações reais, que viram os produtos',
                ],

                $linha->type === 'variable' => [
                    DestinoDaTriagem::Produto,
                    'anúncio "várias cores" — vira um produto, cor como texto',
                ],

                default => [
                    DestinoDaTriagem::Pendente,
                    'tipo desconhecido: '.($linha->type ?? 'nulo'),
                ],
            };

            $linha->forceFill([
                'status_triagem' => $destino->value,
                'motivo_triagem' => $motivo,
            ])->save();
        }
    }

    /**
     * Numera os SKUs em ordem estável de `woo_id`.
     *
     * Estável de propósito: rodar a triagem de novo tem de propor os
     * mesmos códigos. Um SKU que muda entre execuções seria imutável só no
     * nome — e as conferências que a equipe já tivesse feito viravam lixo.
     */
    private function proporSkus(): void
    {
        $proximo = $this->proximoLivre();

        $linhas = StgWooProduct::query()
            ->where('status_triagem', DestinoDaTriagem::Produto->value)
            ->whereNull('sku_proposto')
            ->orderBy('woo_id')
            ->get();

        foreach ($linhas as $linha) {
            $linha->forceFill(['sku_proposto' => $proximo])->save();
            $proximo = $this->incrementar($proximo);
        }
    }

    /**
     * O primeiro código livre, olhando os dois lugares onde SKU já existe.
     *
     * O `GenerateSku` sozinho não basta: ele lê o maior SKU da tabela
     * `products`, que na migração está **vazia** até a carga acontecer.
     * Uma segunda rodada da triagem recomeçaria do `DA-0001` e proporia
     * códigos já prometidos a outras linhas — dois produtos com o mesmo
     * SKU, num campo que a BR-002 declara único e imutável.
     *
     * O bug apareceu no teste de re-execução; em produção teria aparecido
     * como violação de UNIQUE no meio da carga, com metade do catálogo
     * dentro.
     */
    private function proximoLivre(): string
    {
        $doCatalogo = $this->sku->proximo();

        $maiorProposto = StgWooProduct::query()
            ->whereNotNull('sku_proposto')
            ->orderByRaw('LENGTH(sku_proposto) DESC, sku_proposto DESC')
            ->value('sku_proposto');

        if ($maiorProposto === null) {
            return $doCatalogo;
        }

        $candidato = $this->incrementar($maiorProposto);

        // Compara por número, não por texto: `DA-10000` é maior que
        // `DA-9999` apesar de vir antes na ordenação alfabética.
        return $this->numeroDe($candidato) > $this->numeroDe($doCatalogo)
            ? $candidato
            : $doCatalogo;
    }

    private function numeroDe(string $sku): int
    {
        return (int) substr($sku, strlen(GenerateSku::PREFIXO));
    }

    /**
     * O próximo código da sequência, sem consultar o banco a cada volta.
     *
     * O `GenerateSku` lê o maior SKU existente, o que é certo para o
     * cadastro manual (uma criação por vez) e caro aqui: seriam 754
     * consultas com bloqueio. A largura acompanha a do anterior e cresce
     * sozinha ao passar de 9999.
     */
    private function incrementar(string $sku): string
    {
        $numero = (int) substr($sku, strlen(GenerateSku::PREFIXO)) + 1;
        $largura = max(4, strlen((string) $numero));

        return GenerateSku::PREFIXO.str_pad((string) $numero, $largura, '0', STR_PAD_LEFT);
    }

    private function temAtributo(StgWooProduct $linha): bool
    {
        return ! empty($linha->payload['attributes'] ?? []);
    }
}
