<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

use App\Modules\Catalog\Services\ProductLookupService;
use App\Modules\Integrations\WooCommerce\Enums\DestinoDaTriagem;
use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Integrations\WooCommerce\Models\StgWooProduct;
use Illuminate\Support\Collection;

/**
 * Validação da migração do catálogo — docs/17-Migracao F5.
 *
 * Confere se o catálogo (destino) reflete fielmente o staging (origem, o
 * payload cru do Woo). Duas frentes:
 *
 * 1. **Contagens** — quantos foram aprovados na triagem × quantos estão
 *    mapeados no catálogo. Devem bater.
 * 2. **Amostra conferida campo a campo** — para N produtos, o valor
 *    esperado (re-derivado da origem **aqui**, sem reusar o código da
 *    carga) contra o valor atual (perguntado ao Catálogo por Service).
 *
 * A re-derivação independente é de propósito: se a validação chamasse os
 * mesmos métodos de `LoadWooCatalog`, um bug de conversão passaria nos
 * dois lados e a conferência daria verde sobre erro. Reconferir com outra
 * implementação é o que torna o teste honesto.
 */
class ValidateWooCatalog
{
    /** Acima disto o peso é dado sujo (gramas no campo de quilo). */
    private const KG_MAXIMO_PLAUSIVEL = 30.0;

    /** Raízes que não são categoria de produto — vitrine e campanha. */
    private const RAIZES_DE_VITRINE = ['home-site', 'dia-das-maes'];

    public function __construct(private readonly ProductLookupService $catalogo) {}

    /**
     * @return array{
     *   contagens: array{aprovados: int, mapeados: int, bate: bool},
     *   amostra: list<array{sku: string, woo_id: int, nome: string, divergencias: list<string>}>,
     *   conferidos: int,
     *   divergentes: int,
     * }
     */
    public function executar(int $tamanhoAmostra = 30): array
    {
        $aprovados = StgWooProduct::query()
            ->where('status_triagem', DestinoDaTriagem::Produto->value)
            ->whereNotNull('aprovado_em')
            ->count();

        $mapeados = IntegrationMapping::query()
            ->where('remote_system', IntegrationMapping::SISTEMA_WOO)
            ->where('entity_type', 'product')
            ->count();

        $amostra = [];
        $divergentes = 0;

        foreach ($this->amostrar($tamanhoAmostra) as $linha) {
            $divergencias = $this->conferir($linha);

            if ($divergencias !== []) {
                $divergentes++;
            }

            $amostra[] = [
                'sku' => (string) $linha->sku_proposto,
                'woo_id' => (int) $linha->woo_id,
                'nome' => (string) ($linha->nome_proposto ?: $linha->name),
                'divergencias' => $divergencias,
            ];
        }

        return [
            'contagens' => [
                'aprovados' => $aprovados,
                'mapeados' => $mapeados,
                'bate' => $aprovados === $mapeados,
            ],
            'amostra' => $amostra,
            'conferidos' => count($amostra),
            'divergentes' => $divergentes,
        ];
    }

    /**
     * Amostra **estável e espaçada**: ordena por SKU e pega a cada passo
     * fixo. Rodar de novo devolve exatamente os mesmos produtos — uma
     * conferência assinada não pode depender de sorteio que muda.
     *
     * @return Collection<int, StgWooProduct>
     */
    private function amostrar(int $tamanho): Collection
    {
        $aprovados = StgWooProduct::query()
            ->where('status_triagem', DestinoDaTriagem::Produto->value)
            ->whereNotNull('aprovado_em')
            ->orderBy('sku_proposto')
            ->get();

        if ($aprovados->count() <= $tamanho) {
            return $aprovados;
        }

        $passo = (int) floor($aprovados->count() / $tamanho);

        return $aprovados
            ->filter(fn (StgWooProduct $_, int $i): bool => $i % $passo === 0)
            ->take($tamanho)
            ->values();
    }

    /**
     * Os campos que não batem entre a origem e o catálogo.
     *
     * @return list<string>
     */
    private function conferir(StgWooProduct $linha): array
    {
        $local = IntegrationMapping::localDe('product', $linha->woo_id);

        if ($local === null) {
            return ['não está no catálogo (sem mapeamento)'];
        }

        $atual = $this->catalogo->snapshot($local);

        if ($atual === null) {
            return ['mapeado, mas o produto não existe'];
        }

        $divergencias = [];

        $nomeEsperado = (string) ($linha->nome_proposto ?: $linha->name);
        if ($atual->name !== $nomeEsperado) {
            $divergencias[] = "nome: origem “{$nomeEsperado}” × catálogo “{$atual->name}”";
        }

        if (! $this->mesmoDinheiro($this->precoEsperado($linha), $atual->retailPrice)) {
            $divergencias[] = 'preço de varejo diverge da origem';
        }

        if ($this->pesoEsperado($linha->weight) !== $atual->weightG) {
            $divergencias[] = 'peso (g) diverge da conversão esperada';
        }

        if ($this->corEsperada($linha) !== $atual->color) {
            $divergencias[] = 'cor diverge da origem';
        }

        if (($erro = $this->conferirCategoria($linha, $atual->categoryName)) !== null) {
            $divergencias[] = $erro;
        }

        return $divergencias;
    }

    private function precoEsperado(StgWooProduct $linha): ?string
    {
        $preco = $linha->regular_price;

        return ($preco !== null && $preco !== '' && (float) $preco > 0) ? (string) $preco : null;
    }

    private function mesmoDinheiro(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        // Por valor, não por texto: a origem manda "89.9" e o catálogo
        // guarda "89.90" — o mesmo dinheiro escrito de dois jeitos.
        return bccomp($a, $b, 2) === 0;
    }

    private function pesoEsperado(?string $bruto): ?string
    {
        if ($bruto === null || $bruto === '') {
            return null;
        }

        $kg = (float) $bruto;

        if ($kg <= 0 || $kg > self::KG_MAXIMO_PLAUSIVEL) {
            return null;
        }

        return number_format($kg * 1000, 3, '.', '');
    }

    private function corEsperada(StgWooProduct $linha): ?string
    {
        foreach ((array) ($linha->payload['attributes'] ?? []) as $atributo) {
            if (! is_array($atributo) || ($atributo['slug'] ?? '') !== 'pa_cor') {
                continue;
            }

            $opcoes = isset($atributo['option'])
                ? [$atributo['option']]
                : (array) ($atributo['options'] ?? []);

            return $opcoes === [] ? null : mb_substr(implode(', ', $opcoes), 0, 255);
        }

        return null;
    }

    /**
     * Conferência de categoria (leve, mas suficiente para a F5): a
     * categoria escolhida pelo catálogo tem de ser **uma das** que o
     * produto tinha no Woo, e **não** pode ser de vitrine — que é
     * exatamente a classe de erro que a carga já corrigiu.
     *
     * Não re-deriva a "mais profunda" para não reimplementar a árvore
     * inteira; a correção do vitrine já foi conferida em produção (0
     * produtos em categoria de vitrine após a recarga).
     */
    private function conferirCategoria(StgWooProduct $linha, ?string $categoriaAtual): ?string
    {
        $doWoo = [];

        foreach ((array) ($linha->payload['categories'] ?? []) as $categoria) {
            if (is_array($categoria) && isset($categoria['name']) && is_string($categoria['name'])) {
                $doWoo[] = $categoria['name'];
                $slug = is_string($categoria['slug'] ?? null) ? $categoria['slug'] : '';

                if ($categoriaAtual === $categoria['name'] && in_array($slug, self::RAIZES_DE_VITRINE, true)) {
                    return "categoria de vitrine escolhida: “{$categoriaAtual}”";
                }
            }
        }

        if ($categoriaAtual !== null && $doWoo !== [] && ! in_array($categoriaAtual, $doWoo, true)) {
            return "categoria “{$categoriaAtual}” não estava entre as do Woo";
        }

        return null;
    }
}
