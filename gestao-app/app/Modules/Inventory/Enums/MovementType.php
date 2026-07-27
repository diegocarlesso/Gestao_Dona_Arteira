<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * Os tipos de movimento do ledger — docs/09-Estoque §3, BR-202.
 *
 * Cada tipo carrega o **sinal**, e é isso que impede uma venda de
 * aumentar o estoque porque alguém esqueceu o menos ao chamar o serviço.
 * O sinal é do tipo, não do chamador.
 */
enum MovementType: string
{
    case PurchaseReceipt = 'purchase_receipt';
    case ProductionInput = 'production_input';
    case ProductionOutput = 'production_output';
    case SaleShipment = 'sale_shipment';
    case ReturnIn = 'return_in';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Loss = 'loss';

    /**
     * `+1` entra, `-1` sai.
     */
    public function sinal(): int
    {
        return match ($this) {
            self::PurchaseReceipt,
            self::ProductionOutput,
            self::ReturnIn,
            self::AdjustmentIn,
            self::TransferIn => 1,

            self::ProductionInput,
            self::SaleShipment,
            self::AdjustmentOut,
            self::TransferOut,
            self::Loss => -1,
        };
    }

    public function entrada(): bool
    {
        return $this->sinal() === 1;
    }

    /**
     * Ajuste de contagem — o único movimento que nasce de gente olhando a
     * prateleira, e por isso exige permissão própria (`inventory.adjust`)
     * e motivo obrigatório (BR-205).
     */
    public function ehAjuste(): bool
    {
        return in_array($this, [self::AdjustmentIn, self::AdjustmentOut], true);
    }

    /**
     * Movimento cujo motivo é obrigatório.
     *
     * Ajuste e perda existem porque algo saiu do previsto; sem o motivo,
     * o extrato mostra que o saldo mudou e não diz por quê — que é
     * exatamente a pergunta pela qual este módulo existe.
     */
    public function exigeMotivo(): bool
    {
        return $this->ehAjuste() || $this === self::Loss;
    }

    public function label(): string
    {
        return match ($this) {
            self::PurchaseReceipt => 'Recebimento de compra',
            self::ProductionInput => 'Consumo em produção',
            self::ProductionOutput => 'Produção concluída',
            self::SaleShipment => 'Expedição de venda',
            self::ReturnIn => 'Devolução de venda',
            self::AdjustmentIn => 'Ajuste de entrada',
            self::AdjustmentOut => 'Ajuste de saída',
            self::TransferIn => 'Transferência recebida',
            self::TransferOut => 'Transferência enviada',
            self::Loss => 'Perda',
        };
    }
}
