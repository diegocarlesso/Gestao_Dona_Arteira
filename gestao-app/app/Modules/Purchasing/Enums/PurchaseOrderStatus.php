<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

/**
 * Estado do pedido de compra — BR-402, docs/11-Compras §3 (Fase 1).
 *
 * `rascunho → enviado → confirmado`, com `cancelado` saindo dos dois
 * primeiros. **Não há `recebido`**: o recebimento físico com conferência é
 * a Fase 2 (junto do Estoque), e anunciar aqui um estado que nenhum
 * caminho do código alcança seria prometer um fluxo que não existe — o
 * mesmo cuidado que `OrderStatus` tomou ao deixar `Pago` de fora.
 *
 * `Confirmed` é o estado em que o PC vira dívida: é ele que gera o título
 * a pagar (nota de fase da pasta 11).
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Sent => 'Enviado',
            self::Confirmed => 'Confirmado',
            self::Cancelled => 'Cancelado',
        };
    }

    /**
     * Ainda aceita edição de itens, fornecedor e valores.
     */
    public function rascunho(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Pode virar dívida.
     *
     * Inclui o rascunho de propósito: a compra informal por telefone/
     * WhatsApp existe e é o risco número um da pasta 11 §7 — "melhor
     * registrado depois do que nunca". Exigir a passagem por `Enviado`
     * num PC retroativo faria o operador registrar um envio que não
     * aconteceu, que é pior do que não ter o passo.
     */
    public function confirmavel(): bool
    {
        return in_array($this, [self::Draft, self::Sent], true);
    }

    /**
     * Cancelar vale enquanto o PC é só intenção.
     *
     * **Confirmado não cancela por aqui**: ele já gerou título a pagar, e
     * sumir com a dívida sem contrapartida deixaria o financeiro
     * desalinhado com o que se deve. Desfazer uma compra confirmada é
     * estorno no Financeiro (BR-504) — fluxo do Gate 04, fora desta fase.
     */
    public function cancelavel(): bool
    {
        return in_array($this, [self::Draft, self::Sent], true);
    }
}
