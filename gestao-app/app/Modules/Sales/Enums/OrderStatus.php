<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

/**
 * Estado do pedido — BR-303, docs/10-Vendas §3.
 *
 * Este corte do Gate 02 (pasta 10 §2.1) implementa só o começo da máquina:
 * `draft` → `confirmed` (reserva estoque) → `cancelled` (libera). Pago,
 * separação, embalagem, expedição, entrega e devolução são os cortes
 * seguintes — os casos entram quando o comportamento deles existir, para
 * o enum não anunciar transição que ninguém faz.
 */
enum OrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Confirmed => 'Confirmado',
            self::Cancelled => 'Cancelado',
        };
    }

    /**
     * Ainda aceita edição de itens e cliente.
     */
    public function rascunho(): bool
    {
        return $this === self::Draft;
    }

    public function cancelavel(): bool
    {
        return in_array($this, [self::Draft, self::Confirmed], true);
    }
}
