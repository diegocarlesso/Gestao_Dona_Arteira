<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * Situação do produto no catálogo — docs/32-Catalogo §3.8.
 *
 * Produto **não é excluído** (BR-008): pedido, movimento de estoque e
 * nota fiscal antigos o referenciam. Arquivar tira das telas de venda e
 * do site; o histórico continua explicável.
 */
enum ProductStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Archived => 'Arquivado',
        };
    }

    /** Pode ser vendido e publicado hoje? */
    public function isAvailable(): bool
    {
        return $this === self::Active;
    }
}
