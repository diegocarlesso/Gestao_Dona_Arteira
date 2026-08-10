<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Enums;

/**
 * Situação de uma NF-e — docs/14 §3.
 *
 * Quatro estados e nenhum "erro": erro de transmissão não é estado da nota,
 * é tentativa que não completou — a nota continua `pending` e a fila tenta
 * de novo com o **mesmo número** (BR-602: rejeição não queima número, e
 * timeout muito menos).
 *
 * `Cancelled` é destino de nota **autorizada** que foi cancelada por evento
 * dentro do prazo (BR-604), não de nota que nunca chegou à SEFAZ.
 */
enum InvoiceStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Authorized => 'Autorizada',
            self::Rejected => 'Rejeitada',
            self::Cancelled => 'Cancelada',
        };
    }

    /**
     * A nota já vale como documento fiscal?
     *
     * O que o gate da expedição (BR-309) pergunta.
     */
    public function valeComoDocumento(): bool
    {
        return $this === self::Authorized;
    }

    /**
     * A nota ainda pode ser transmitida (de novo)?
     *
     * Autorizada, não: é imutável. Cancelada, também não. Pendente e
     * rejeitada, sim — rejeitada se corrige e reemite com o mesmo número.
     */
    public function transmissivel(): bool
    {
        return $this === self::Pending || $this === self::Rejected;
    }
}
