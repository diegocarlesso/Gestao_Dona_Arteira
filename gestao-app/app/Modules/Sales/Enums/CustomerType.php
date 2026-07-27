<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

/**
 * Pessoa física ou jurídica — decide o formato do documento (BR-001) e,
 * mais adiante, o tratamento fiscal.
 *
 * O inventário do legado achou **85 pedidos, 100% pessoa física** e zero
 * PJ. A PJ entra assim mesmo: a empresa vende atacado (BR-003 confirmada
 * pelo dono), e atacado é onde a PJ aparece — o canal simplesmente nunca
 * passou pelo site.
 */
enum CustomerType: string
{
    case Individual = 'individual';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Pessoa física',
            self::Company => 'Pessoa jurídica',
        };
    }

    public function documentoEsperado(): string
    {
        return $this === self::Company ? 'CNPJ' : 'CPF';
    }
}
