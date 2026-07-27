<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

use App\Modules\Catalog\Enums\ProductKind;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Services\ImportProductCategoryService;
use App\Modules\Catalog\Services\ImportProductImagesService;
use App\Modules\Catalog\Services\ImportProductService;
use App\Modules\Integrations\WooCommerce\Enums\DestinoDaTriagem;
use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Integrations\WooCommerce\Models\StgWooCategory;
use App\Modules\Integrations\WooCommerce\Models\StgWooProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Carga do staging para o catálogo — docs/17-Migracao F4.
 *
 * Ordem por dependência: **categorias primeiro**, produtos depois — um
 * produto aponta para categoria, não o contrário.
 *
 * **Idempotente** (BR-706): o `integration_mappings` liga o id do Woo ao
 * registro local, então rodar de novo atualiza em vez de duplicar. É o
 * que permite carregar, corrigir algo no staging e recarregar sem limpar
 * o banco.
 *
 * Só entra o que foi **aprovado** (F3), e o SKU vem da proposta aprovada
 * — na migração ele foi decidido antes e conferido por gente.
 *
 * **Não constrói models do Catálogo.** Fala com os Services dele, que são
 * a superfície pública do módulo (ADR-0020). O teste de arquitetura
 * reprova o contrário, e com razão: as invariantes do produto moram no
 * model, e cada módulo que o construísse por fora seria mais um lugar
 * onde elas podem ser esquecidas.
 */
class LoadWooCatalog
{
    /**
     * Acima disto o peso é dado sujo, não peça pesada.
     *
     * O catálogo tem 3 produtos com "970" num campo em quilos — gramas
     * digitadas no lugar errado. Carregar 970 kg produziria frete absurdo
     * **em silêncio**; deixar o peso nulo faz o produto aparecer no
     * relatório de cadastro incompleto, que é onde alguém olha.
     */
    private const KG_MAXIMO_PLAUSIVEL = 30.0;

    /**
     * Raízes que não são categoria de produto — e tudo que desce delas.
     *
     * `HOME SITE` são blocos da vitrine do site e `DIA DAS MÃES` é
     * campanha sazonal: dizem onde a peça apareceu e quando, não o que
     * ela é (RC-06). Sem esta lista, 34 produtos ficaram classificados
     * como "Home Kits" e "DIA DAS MÃES" na primeira carga, porque a
     * escolha era "a primeira que a API listar".
     *
     * A lista é explícita porque **campanha é fato do negócio, não
     * estrutura**: nada no esquema distingue `DIA DAS MÃES` de `BUDAS`.
     * Uma campanha nova no site não é reconhecida sozinha — custo aceito
     * de adiar a modelagem de coleções para o Gate 02 (pasta 32 §3.4).
     *
     * @var list<string>
     */
    private const RAIZES_DE_VITRINE = ['home-site', 'dia-das-maes'];

    public function __construct(
        private readonly ImportProductService $produtos,
        private readonly ImportProductCategoryService $categorias,
        private readonly ImportProductImagesService $imagens,
    ) {}

    /**
     * @return array{categorias: int, produtos: int, precos: int, imagens: int, peso_recusado: int, total_mapeado: int}
     */
    public function executar(): array
    {
        $contagem = ['categorias' => 0, 'produtos' => 0, 'precos' => 0, 'imagens' => 0, 'peso_recusado' => 0, 'total_mapeado' => 0];

        DB::transaction(function () use (&$contagem): void {
            $contagem['categorias'] = $this->carregarCategorias();
            [$produtos, $precos, $imagens, $recusados] = $this->carregarProdutos();
            $contagem['produtos'] = $produtos;
            $contagem['precos'] = $precos;
            $contagem['imagens'] = $imagens;
            $contagem['peso_recusado'] = $recusados;
        });

        // Contado pelo mapeamento, e não pela tabela de produtos: o
        // ADR-0020 impede este módulo de enxergar `Catalog\Models`, e o
        // número que interessa aqui é mesmo "quantos vieram do Woo" — um
        // produto cadastrado à mão no ERP não é resultado desta carga.
        $contagem['total_mapeado'] = IntegrationMapping::query()
            ->where('remote_system', IntegrationMapping::SISTEMA_WOO)
            ->where('entity_type', 'product')
            ->count();

        return $contagem;
    }

    /**
     * Duas passadas: cria todas, depois liga os pais.
     *
     * Uma passada só exigiria que a origem viesse ordenada por
     * profundidade da árvore, o que a API não garante — uma subcategoria
     * pode chegar antes da mãe, e ligar na hora exigiria uma mãe que
     * ainda não existe.
     */
    private function carregarCategorias(): int
    {
        $criadas = 0;

        foreach (StgWooCategory::query()->cursor() as $linha) {
            [$localId, $nova] = $this->categorias->importar(
                IntegrationMapping::localDe('product_category', $linha->woo_id),
                (string) $linha->name,
                $linha->slug,
            );

            $criadas += $nova ? 1 : 0;

            IntegrationMapping::registrar('product_category', $localId, $linha->woo_id);
        }

        foreach (StgWooCategory::query()->whereNotNull('parent_woo_id')->cursor() as $linha) {
            $filhaId = IntegrationMapping::localDe('product_category', $linha->woo_id);
            $maeId = IntegrationMapping::localDe('product_category', (int) $linha->parent_woo_id);

            if ($filhaId !== null && $maeId !== null) {
                $this->categorias->definirMae($filhaId, $maeId);
            }
        }

        return $criadas;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function carregarProdutos(): array
    {
        $criados = 0;
        $precos = 0;
        $imagens = 0;
        $pesoRecusado = 0;

        $linhas = StgWooProduct::query()
            ->where('status_triagem', DestinoDaTriagem::Produto->value)
            ->whereNotNull('aprovado_em')
            ->whereNotNull('sku_proposto')
            ->orderBy('sku_proposto')
            ->cursor();

        // Montado uma vez e passado adiante: são 48 categorias contra 754
        // produtos, e subir a árvore por produto seria refazer o mesmo
        // caminho centenas de vezes.
        $categorias = $this->mapaDeCategorias();

        foreach ($linhas as $linha) {
            [$pesoG, $recusado] = $this->pesoEmGramas($linha->weight);
            $pesoRecusado += $recusado ? 1 : 0;

            $temPreco = $this->temPreco($linha);

            [$localId, $novo] = $this->produtos->importar(
                IntegrationMapping::localDe('product', $linha->woo_id),
                (string) $linha->sku_proposto,
                [
                    'name' => (string) ($linha->nome_proposto ?: $linha->name),
                    'kind' => ProductKind::FinishedGood,
                    'status' => $linha->status === 'publish' ? ProductStatus::Active : ProductStatus::Archived,
                    'color' => $this->cor($linha),
                    'weight_g' => $pesoG,
                    'height_cm' => $this->dimensao($linha, 'height'),
                    'width_cm' => $this->dimensao($linha, 'width'),
                    'depth_cm' => $this->dimensao($linha, 'length'),
                    'description' => $linha->payload['description'] ?? null,
                    'product_category_id' => $this->categoriaDe($linha, $categorias),
                    'sell_on_woo' => true,
                ],
                $temPreco ? (string) $linha->regular_price : null,
            );

            $criados += $novo ? 1 : 0;
            $precos += ($novo && $temPreco) ? 1 : 0;
            $imagens += $this->imagens->importar($localId, $this->imagensDe($linha));

            IntegrationMapping::registrar('product', $localId, $linha->woo_id);
        }

        return [$criados, $precos, $imagens, $pesoRecusado];
    }

    /**
     * Converte o peso do Woo (quilos) para a unidade do ERP (gramas).
     *
     * @return array{0: string|null, 1: bool} peso e se foi recusado por implausível
     */
    private function pesoEmGramas(?string $bruto): array
    {
        if ($bruto === null || $bruto === '') {
            return [null, false];
        }

        $kg = (float) $bruto;

        if ($kg <= 0) {
            return [null, false];
        }

        if ($kg > self::KG_MAXIMO_PLAUSIVEL) {
            // Peça decorativa de gesso não pesa 970 kg — é grama digitada
            // em campo de quilo. Melhor sem peso e sinalizado do que com
            // peso errado e silencioso.
            return [null, true];
        }

        return [number_format($kg * 1000, 3, '.', ''), false];
    }

    private function dimensao(StgWooProduct $linha, string $chave): ?string
    {
        $valor = $linha->payload['dimensions'][$chave] ?? null;

        return is_string($valor) && $valor !== '' && (float) $valor > 0 ? $valor : null;
    }

    /**
     * As cores que o anúncio lista, como texto.
     *
     * Decisão do dono em 2026-07-27: nos anúncios "várias cores", um
     * anúncio vira um produto e a cor fica descritiva. Expandir cada cor
     * em produto próprio só faria sentido se a operação mantivesse peças
     * pintadas em estoque.
     */
    private function cor(StgWooProduct $linha): ?string
    {
        foreach ((array) ($linha->payload['attributes'] ?? []) as $atributo) {
            if (! is_array($atributo) || ($atributo['slug'] ?? '') !== 'pa_cor') {
                continue;
            }

            // O produto lista as cores possíveis em `options` (plural); a
            // variação traz a cor escolhida em `option` (singular). O
            // singular/plural é do Woo, não descuido nosso — e ler só um
            // dos dois perderia metade dos casos.
            $opcoes = isset($atributo['option'])
                ? [$atributo['option']]
                : (array) ($atributo['options'] ?? []);

            return $opcoes === [] ? null : mb_substr(implode(', ', $opcoes), 0, 255);
        }

        return null;
    }

    /**
     * As imagens do item, normalizadas — ADR-0017, fase 1.
     *
     * O produto traz uma galeria em `images` (plural); a variação traz uma
     * imagem só em `image` (singular). É o mesmo singular/plural da cor —
     * um padrão do Woo, não descuido de quem extraiu.
     *
     * Guardamos a URL, não o arquivo: a mídia segue no WordPress até o
     * Gate 06. O `thumbnail` que a origem já oferece evita baixar a foto
     * inteira para exibir numa prévia de listagem.
     *
     * @return list<array{remote_id: string|int|null, url: string, thumbnail_url: string|null, alt: string|null}>
     */
    private function imagensDe(StgWooProduct $linha): array
    {
        $brutas = $linha->payload['images'] ?? null;

        if (! is_array($brutas) || $brutas === []) {
            $unica = $linha->payload['image'] ?? null;
            $brutas = is_array($unica) && $unica !== [] ? [$unica] : [];
        }

        $imagens = [];

        foreach ($brutas as $bruta) {
            if (! is_array($bruta) || ! is_string($bruta['src'] ?? null) || $bruta['src'] === '') {
                continue;
            }

            $imagens[] = [
                'remote_id' => $bruta['id'] ?? null,
                'url' => $bruta['src'],
                'thumbnail_url' => is_string($bruta['thumbnail'] ?? null) && $bruta['thumbnail'] !== ''
                    ? $bruta['thumbnail']
                    : null,
                'alt' => is_string($bruta['alt'] ?? null) && $bruta['alt'] !== '' ? $bruta['alt'] : null,
            ];
        }

        return $imagens;
    }

    /**
     * A categoria canônica do produto — pasta 32 §3.4.
     *
     * No Woo o produto está em 1,7 categoria em média; o ERP guarda uma.
     * Escolher "a primeira que a API listar" é sortear, e o sorteio já
     * saiu errado 34 vezes. A regra: descarta vitrine, e entre as reais
     * fica com a **mais profunda** — 337 dos 346 casos de múltipla
     * categoria são um par mãe/filha, onde a filha é a resposta.
     *
     * @param  array<int, array{local: int, profundidade: int, vitrine: bool}>  $categorias
     */
    private function categoriaDe(StgWooProduct $linha, array $categorias): ?int
    {
        $escolhida = null;
        $profundidade = -1;

        foreach ((array) ($linha->payload['categories'] ?? []) as $categoria) {
            if (! is_array($categoria) || ! isset($categoria['id'])) {
                continue;
            }

            $dados = $categorias[(int) $categoria['id']] ?? null;

            if ($dados === null || $dados['vitrine']) {
                continue;
            }

            // Estritamente maior mantém a primeira em caso de empate — os
            // ~9 produtos com duas categorias temáticas sem parentesco
            // ficam com a ordem da origem, e a decisão é humana.
            if ($dados['profundidade'] > $profundidade) {
                $escolhida = $dados['local'];
                $profundidade = $dados['profundidade'];
            }
        }

        return $escolhida;
    }

    /**
     * Cada categoria do staging com o id local, a profundidade na árvore
     * e se ela descende de uma raiz de vitrine.
     *
     * @return array<int, array{local: int, profundidade: int, vitrine: bool}>
     */
    private function mapaDeCategorias(): array
    {
        $linhas = StgWooCategory::query()->get()->keyBy('woo_id');
        $mapa = [];

        foreach ($linhas as $wooId => $_) {
            $local = IntegrationMapping::localDe('product_category', (int) $wooId);

            if ($local === null) {
                continue;
            }

            [$profundidade, $vitrine] = $this->ancestralidade($linhas, (int) $wooId);

            $mapa[(int) $wooId] = [
                'local' => $local,
                'profundidade' => $profundidade,
                'vitrine' => $vitrine,
            ];
        }

        return $mapa;
    }

    /**
     * Sobe a árvore até a raiz: quantos degraus, e se a raiz é vitrine.
     *
     * @param  Collection<int, StgWooCategory>  $linhas
     * @return array{0: int, 1: bool}
     */
    private function ancestralidade(Collection $linhas, int $wooId): array
    {
        $profundidade = 0;
        $atual = $wooId;
        $vistos = [];

        while (true) {
            $linha = $linhas->get($atual);

            // `isset($vistos)` guarda contra ciclo na árvore vinda da
            // origem. Não é hipótese remota: `parent` no Woo é um id
            // solto, sem FK, e um laço aqui travaria a carga inteira
            // dentro de uma transação.
            if ($linha === null || isset($vistos[$atual])) {
                return [$profundidade, false];
            }

            $vistos[$atual] = true;
            $pai = (int) ($linha->parent_woo_id ?? 0);

            if ($pai === 0) {
                return [$profundidade, in_array((string) $linha->slug, self::RAIZES_DE_VITRINE, true)];
            }

            $profundidade++;
            $atual = $pai;
        }
    }

    private function temPreco(StgWooProduct $linha): bool
    {
        return $linha->regular_price !== null
            && $linha->regular_price !== ''
            && (float) $linha->regular_price > 0;
    }
}
