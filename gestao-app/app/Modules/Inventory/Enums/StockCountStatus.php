<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * O ciclo de uma contagem — BR-205.
 *
 * `counting` → `awaiting_approval` → `approved`, com `cancelled` como
 * saída de qualquer ponto antes da aprovação. Depois de aprovada não há
 * volta: os ajustes já viraram movimento no ledger, e movimento não se
 * desfaz — se desfaz por contra-movimento (BR-202).
 */
enum StockCountStatus: string
{
    case Counting = 'counting';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Counting => 'Em contagem',
            self::AwaitingApproval => 'Aguardando aprovação',
            self::Approved => 'Aprovada',
            self::Cancelled => 'Cancelada',
        };
    }

    /**
     * Ainda aceita quantidade contada.
     */
    public function aberta(): bool
    {
        return $this === self::Counting;
    }

    public function encerrada(): bool
    {
        return in_array($this, [self::Approved, self::Cancelled], true);
    }
}
