<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * As duas listas de preço — BR-003, docs/32-Catalogo §3.5.
 *
 * A empresa vende nos dois canais (confirmado pelo dono em 2026-07-25).
 * O critério de quem tem direito ao atacado é da venda (BR-301), não do
 * catálogo: aqui só existem as listas e seus preços.
 */
enum PriceList: string
{
    case Retail = 'retail';
    case Wholesale = 'wholesale';

    public function label(): string
    {
        return match ($this) {
            self::Retail => 'Varejo',
            self::Wholesale => 'Atacado',
        };
    }
}
