<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * Tipo de local de estoque — docs/09-Estoque, pasta 30.
 *
 * Hoje só o ateliê existe de verdade. Feira e consignação aparecem no
 * domínio e entram como `Store` quando a operação as usar; o tipo serve
 * para relatório e para regra futura (consignação não publica no site),
 * não para restringir movimento.
 */
enum LocationType: string
{
    case Atelier = 'atelier';
    case Warehouse = 'warehouse';
    case Store = 'store';

    public function label(): string
    {
        return match ($this) {
            self::Atelier => 'Ateliê',
            self::Warehouse => 'Depósito',
            self::Store => 'Loja',
        };
    }
}
