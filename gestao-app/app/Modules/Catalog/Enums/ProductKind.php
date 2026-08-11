<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * O que um produto é — docs/32-Catalogo §3.1.
 *
 * Peça, matéria-prima, embalagem, revenda e insumo dividem a mesma tabela
 * e o mesmo estoque (BR-207). A distinção importa fora do catálogo:
 * `resale` tem NCM próprio e fluxo de compra, `finished_good` tem ficha
 * técnica e ordem de produção, e os demais são consumidos por ela.
 */
enum ProductKind: string
{
    case FinishedGood = 'finished_good';
    case RawMaterial = 'raw_material';
    case Packaging = 'packaging';
    case Resale = 'resale';
    case Supply = 'supply';

    public function label(): string
    {
        return match ($this) {
            self::FinishedGood => 'Peça acabada',
            self::RawMaterial => 'Matéria-prima',
            self::Packaging => 'Embalagem',
            self::Resale => 'Revenda',
            self::Supply => 'Insumo de consumo',
        };
    }

    /**
     * É vendável ao cliente?
     *
     * Matéria-prima e insumo existem para serem consumidos, não vendidos.
     * Usado pela venda (pasta 10) para não oferecer gesso num pedido.
     */
    public function isSellable(): bool
    {
        return match ($this) {
            self::FinishedGood, self::Resale => true,
            // Embalagem é vendável em tese (avulsa), mas não no catálogo
            // da loja — se isso mudar, é decisão de venda, não daqui.
            self::Packaging, self::RawMaterial, self::Supply => false,
        };
    }

    /**
     * É fabricado pela casa?
     *
     * Só o que é produzido tem ficha técnica e ordem de produção
     * (pasta 08). Revenda entra por compra (pasta 11).
     */
    public function isManufactured(): bool
    {
        return $this === self::FinishedGood;
    }

    /**
     * Compra-se de fornecedor?
     *
     * Peça acabada é pintura interna sobre peça crua comprada — não existe
     * fornecedor de "buda azul" (ADR-0023). O que se compra é a peça crua
     * (`raw_material`), a embalagem, o insumo e a revenda; a cor nasce
     * depois, no ateliê. Usado por Compras (pasta 11) para não oferecer no
     * pedido uma cor que nenhum fornecedor tem.
     */
    public function isPurchasable(): bool
    {
        return $this !== self::FinishedGood;
    }
}
