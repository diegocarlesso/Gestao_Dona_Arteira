<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Enums;

/**
 * A classe de divergência que a reconciliação registra — ADR-0025, docs/16 §5.
 *
 * Só uma hoje: o **total** de um pedido já importado mudou no Woo depois da
 * importação. O valor gravado (`checksum_mismatch`) é genérico de propósito —
 * quando a reconciliação de estoque/produto entrar (o gatilho de revisão do
 * ADR-0025), ela reusa o mesmo `kind` sobre outro `entity_type`, sem novo
 * tipo. Pedido **ausente** não é divergência aqui: ele é importado e deixa
 * rastro natural no log de eventos (`order.pulled`).
 */
enum TipoDeDivergencia: string
{
    /** Total do pedido no Woo diverge do congelado na importação (BR-702/BR-304). */
    case ChecksumMismatch = 'checksum_mismatch';

    public function label(): string
    {
        return match ($this) {
            self::ChecksumMismatch => 'Total divergente',
        };
    }
}
