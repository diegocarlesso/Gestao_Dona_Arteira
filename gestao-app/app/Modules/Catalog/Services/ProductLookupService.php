<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\DTO\ProductFiscalData;
use App\Modules\Catalog\DTO\ProductSnapshot;
use App\Modules\Catalog\DTO\ProductSummary;
use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Enums\ProductKind;
use App\Modules\Catalog\Models\Product;

/**
 * A porta pela qual os outros módulos perguntam ao Catálogo quem é um
 * produto — ADR-0020.
 *
 * O Estoque tem `product_id` no movimento e precisa escrever o nome na
 * tela; sem este Service, ou ele referenciaria `Catalog\Models` (proibido)
 * ou consultaria a tabela `products` direto (também proibido). A fronteira
 * só se sustenta se existir a porta.
 *
 * Devolve `ProductSummary`, nunca `Product`: o ADR avisa que `arch()`
 * verifica namespace e não semântica, e um Service que devolvesse o model
 * passaria no teste enquanto vazava o acoplamento inteiro.
 */
class ProductLookupService
{
    /**
     * Os cartões de visita de vários produtos, indexados por id.
     *
     * Em lote de propósito: a tela de posição mostra 20 saldos, e uma
     * consulta por linha seria o problema N+1 atravessando a fronteira do
     * módulo, onde ele é mais difícil de enxergar.
     *
     * @param  list<int>  $ids
     * @return array<int, ProductSummary>
     */
    public function resumos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->get(['id', 'public_id', 'sku', 'name', 'color', 'unit', 'min_stock'])
            ->mapWithKeys(fn (Product $p): array => [$p->id => $this->resumoDe($p)])
            ->all();
    }

    public function resumo(int $id): ?ProductSummary
    {
        $produto = Product::query()->find($id, ['id', 'public_id', 'sku', 'name', 'color', 'unit', 'min_stock']);

        return $produto === null ? null : $this->resumoDe($produto);
    }

    public function resumoPorPublicId(string $publicId): ?ProductSummary
    {
        $produto = Product::query()
            ->where('public_id', $publicId)
            ->first(['id', 'public_id', 'sku', 'name', 'color', 'unit', 'min_stock']);

        return $produto === null ? null : $this->resumoDe($produto);
    }

    /**
     * Ids dos produtos que casam com um termo — nome, código ou cor.
     *
     * O Estoque filtra saldos por produto, mas quem sabe o que é "casar
     * com o termo" é o Catálogo: a busca por SKU **e** nome existe porque
     * o código é neutro (ADR-0022) e ninguém o decora, mas quem tem a
     * etiqueta na mão digita exatamente ele. Duplicar essa regra do outro
     * lado da fronteira seria criar duas buscas que divergem com o tempo.
     *
     * @return list<int>
     */
    public function idsPorTermo(string $termo): array
    {
        return Product::query()
            ->where(fn ($q) => $q->where('name', 'like', "%{$termo}%")
                ->orWhere('sku', 'like', "%{$termo}%")
                ->orWhere('color', 'like', "%{$termo}%"))
            ->pluck('id')
            ->all();
    }

    /**
     * Ids de tudo que está ativo no catálogo.
     *
     * O Estoque usa para congelar a lista de uma contagem física (BR-205)
     * — e quem decide o que é "ativo" é o Catálogo, porque produto
     * arquivado não deve entrar numa contagem nova mesmo que ainda tenha
     * saldo. O saldo remanescente de um arquivado se resolve por ajuste
     * nomeado, não por inventário de rotina.
     *
     * @return list<int>
     */
    public function idsAtivos(): array
    {
        return Product::query()->active()->orderBy('id')->pluck('id')->all();
    }

    /**
     * O estado atual do produto para a conferência da migração (F5).
     *
     * Existe para que a validação (no módulo Integrations) compare a carga
     * com a origem **sem** ler `Catalog\Models` nem a tabela `products`
     * (ADR-0020): ela pergunta ao Catálogo os valores de hoje e cruza com
     * o que o staging tinha.
     */
    public function snapshot(int $id): ?ProductSnapshot
    {
        $produto = Product::query()->with('category:id,name')->find($id);

        if ($produto === null) {
            return null;
        }

        return new ProductSnapshot(
            sku: $produto->sku,
            name: $produto->name,
            color: $produto->color,
            weightG: $produto->weight_g,
            retailPrice: $produto->precoVigente(PriceList::Retail)?->price,
            categoryName: $produto->category?->name,
        );
    }

    /**
     * Os dados fiscais de vários produtos, indexados por id — BR-606.
     *
     * Existe pelo mesmo motivo dos `resumos()`: o faturamento tem
     * `product_id` na linha do pedido e precisa do NCM para montar o XML,
     * mas não pode ler `Catalog\Models` (ADR-0020). Em lote porque uma nota
     * tem várias linhas e uma consulta por item seria o N+1 escondido atrás
     * da fronteira.
     *
     * @param  list<int>  $ids
     * @return array<int, ProductFiscalData>
     */
    public function dadosFiscais(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->get(['id', 'sku', 'name', 'unit', 'ncm', 'cest', 'origin', 'gtin'])
            ->mapWithKeys(fn (Product $p): array => [$p->id => new ProductFiscalData(
                id: $p->id,
                sku: $p->sku,
                name: $p->name,
                unit: $p->unit,
                ncm: $p->ncm,
                cest: $p->cest,
                origin: $p->origin,
                gtin: $p->gtin,
            )])
            ->all();
    }

    /**
     * Produtos que casam com o termo, **com o preço de varejo vigente** —
     * para o autocomplete de item do pedido (Vendas). Quem sabe buscar e
     * quem sabe o preço é o Catálogo (ADR-0020); Vendas só exibe.
     *
     * @return list<array{public_id: string, sku: string, name: string, color: string|null, retail_price: string|null}>
     */
    public function buscarParaVenda(string $termo, int $limite = 15): array
    {
        return Product::query()
            ->active()
            ->where(fn ($q) => $q->where('name', 'like', "%{$termo}%")
                ->orWhere('sku', 'like', "%{$termo}%")
                ->orWhere('color', 'like', "%{$termo}%"))
            ->orderBy('name')
            ->limit($limite)
            ->get(['id', 'public_id', 'sku', 'name', 'color'])
            ->map(fn (Product $p): array => [
                'public_id' => $p->public_id,
                'sku' => $p->sku,
                'name' => $p->name,
                'color' => $p->color,
                'retail_price' => $p->precoVigente(PriceList::Retail)?->price,
            ])
            ->all();
    }

    /**
     * Produtos que casam com o termo, restritos ao que se compra de
     * fornecedor — para o autocomplete de item do pedido de compra.
     *
     * **Não inclui peça acabada.** A peça acabada é a peça crua já pintada
     * pelo ateliê (ADR-0023) — nenhum fornecedor vende "buda azul", vende
     * a peça crua que uma pintura interna transforma em peça de qualquer
     * cor do catálogo. Oferecer peça acabada aqui faria o comprador
     * escolher uma cor que não existe do lado do fornecedor.
     *
     * @return list<array{public_id: string, sku: string, name: string, color: string|null, unit: string}>
     */
    public function buscarParaCompra(string $termo, int $limite = 15): array
    {
        $tiposComprapveis = array_values(array_map(
            fn (ProductKind $k): string => $k->value,
            array_filter(ProductKind::cases(), fn (ProductKind $k): bool => $k->isPurchasable()),
        ));

        return Product::query()
            ->active()
            ->whereIn('kind', $tiposComprapveis)
            ->where(fn ($q) => $q->where('name', 'like', "%{$termo}%")
                ->orWhere('sku', 'like', "%{$termo}%")
                ->orWhere('color', 'like', "%{$termo}%"))
            ->orderBy('name')
            ->limit($limite)
            ->get(['public_id', 'sku', 'name', 'color', 'unit'])
            ->map(fn (Product $p): array => [
                'public_id' => $p->public_id,
                'sku' => $p->sku,
                'name' => $p->name,
                'color' => $p->color,
                'unit' => $p->unit,
            ])
            ->all();
    }

    private function resumoDe(Product $produto): ProductSummary
    {
        return new ProductSummary(
            id: $produto->id,
            publicId: $produto->public_id,
            sku: $produto->sku,
            name: $produto->name,
            color: $produto->color,
            unit: $produto->unit,
            minStock: $produto->min_stock,
        );
    }
}
