<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

/**
 * PIX com vencimento ou boleto — mesmo caso de uso ("Cobrança"), tratados
 * sob a mesma interface (ADR-0018 §Decisão, item 2).
 */
enum TipoCobranca: string
{
    case Pix = 'pix';
    case Boleto = 'boleto';

    public function label(): string
    {
        return match ($this) {
            self::Pix => 'PIX',
            self::Boleto => 'Boleto',
        };
    }

    /**
     * Boleto exige endereço completo do pagador; PIX não (ADR-0018 §3.1).
     */
    public function exigeEnderecoDoPagador(): bool
    {
        return $this === self::Boleto;
    }
}
