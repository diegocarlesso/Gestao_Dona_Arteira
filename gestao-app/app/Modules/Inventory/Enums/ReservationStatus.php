<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * O ciclo de uma reserva — BR-203.
 *
 * `active` → `consumed` (a peça foi expedida) **ou** `released` (o pedido
 * foi cancelado). Só a reserva `active` conta em `qty_reserved`; as outras
 * duas já devolveram ou baixaram o número.
 */
enum ReservationStatus: string
{
    case Active = 'active';
    case Consumed = 'consumed';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativa',
            self::Consumed => 'Consumida (expedida)',
            self::Released => 'Liberada (cancelada)',
        };
    }

    public function ativa(): bool
    {
        return $this === self::Active;
    }
}
