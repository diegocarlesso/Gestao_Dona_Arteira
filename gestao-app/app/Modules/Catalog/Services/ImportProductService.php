<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Models\Product;

/**
 * Cria ou atualiza um produto vindo de uma importação em massa.
 *
 * Existe para que **a migração não construa `Product` por fora**: o
 * ADR-0020 diz que a superfície pública de um módulo são seus Services, e
 * o teste de arquitetura reprova quem importar `Catalog\Models` de fora.
 * Não é formalidade — as invariantes do produto (SKU imutável, slug
 * único, ULID público) moram no model, e cada módulo que o construísse
 * por conta própria seria mais um lugar onde elas podem ser esquecidas.
 *
 * Difere do `CreateProductService` num ponto só, e é o ponto: **o SKU vem
 * de fora**. No cadastro manual ele é gerado na hora; na migração foi
 * proposto pela triagem e aprovado por gente antes (docs/17 F3), e usar o
 * gerador aqui desfaria essa conferência.
 *
 * Idempotente por `$localId`: recebendo um id existente, atualiza. É o
 * que permite recarregar sem duplicar (BR-706).
 */
class ImportProductService
{
    public function __construct(private readonly SetProductPriceService $precos) {}

    /**
     * @param  int|null  $localId  id do produto já importado antes, se houver
     * @param  array<string, mixed>  $atributos
     * @return array{0: int, 1: bool} id do produto e se foi criado agora
     */
    public function importar(
        ?int $localId,
        string $sku,
        array $atributos,
        ?string $precoVarejo = null,
    ): array {
        $produto = $localId !== null ? Product::query()->find($localId) : null;
        $novo = $produto === null;

        if ($novo) {
            $produto = new Product;
            // Só na criação: a partir daqui o model recusa alteração
            // (BR-002), e é essa recusa que torna o código confiável como
            // chave de casamento com pedido e nota fiscal.
            $produto->sku = $sku;
        }

        $produto->forceFill($atributos)->save();

        // Preço só na criação. Recarregar não deve criar linha de preço
        // repetida: o histórico registra mudança de preço do negócio, não
        // repetição de comando (BR-003).
        if ($novo && $precoVarejo !== null && $precoVarejo !== '' && (float) $precoVarejo > 0) {
            $this->precos->handle($produto, PriceList::Retail, $precoVarejo);
        }

        return [(int) $produto->getKey(), $novo];
    }
}
