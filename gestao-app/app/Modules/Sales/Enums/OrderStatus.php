<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

/**
 * Estado do pedido — BR-303, docs/10-Vendas §3.
 *
 * A máquina cresce por corte (pasta 10 §2.1). Corte 2: `draft → confirmed
 * → cancelled`. Corte 3 (fulfillment): `confirmed → separando → embalado →
 * expedido → entregue`.
 *
 * **`Pago` ainda não existe.** O modelo da pasta 10 §3 põe Pago entre
 * Confirmado e a separação, mas pagamento é o Gate 04 (Financeiro). O
 * pedido do site já entra Confirmado (corte 4) e a separação parte daí; o
 * caso entra quando o comportamento dele existir, para o enum não anunciar
 * transição que ninguém faz.
 */
enum OrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Separando = 'separando';
    case Embalado = 'embalado';
    case Expedido = 'expedido';
    case Entregue = 'entregue';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Confirmed => 'Confirmado',
            self::Separando => 'Em separação',
            self::Embalado => 'Embalado',
            self::Expedido => 'Expedido',
            self::Entregue => 'Entregue',
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

    /**
     * Cancelar libera a reserva (BR-203). Vale enquanto a peça ainda está
     * **prometida** e não saiu: rascunho (não reservou), confirmado e as
     * etapas de separação/embalagem (reserva ativa, ainda liberável).
     *
     * Expedido e Entregue **não** cancelam por aqui: a reserva já virou
     * baixa no ledger (`sale_shipment`), a peça saiu — desfazer é
     * devolução (`return_in`), fluxo próprio de um corte adiante.
     */
    public function cancelavel(): bool
    {
        return in_array($this, [self::Draft, self::Confirmed, self::Separando, self::Embalado], true);
    }

    /**
     * As etapas em que o pedido está reservado mas ainda não expedido — a
     * reserva pesa em `qty_reserved` e a peça não saiu.
     */
    public function reservaAtiva(): bool
    {
        return in_array($this, [self::Confirmed, self::Separando, self::Embalado], true);
    }
}
