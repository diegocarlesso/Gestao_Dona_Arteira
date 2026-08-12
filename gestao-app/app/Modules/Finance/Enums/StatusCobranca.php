<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

/**
 * Vocabulário interno de status de uma `BillingCharge` — docs/12-Financeiro/
 * 01-cobranca-e-boletos.md §3/§4.
 *
 * Deliberadamente **não** é o vocabulário do Mercado Pago (`pending`,
 * `approved`, `rejected`, `in_process`...): a tradução externo→interno é
 * responsabilidade do adapter em `Integrations\MercadoPago` (Mappers/
 * StatusMap, docs/15 §3) — o Financeiro nunca deveria mudar de significado
 * porque o provedor renomeou um status na v2 da API dele.
 */
enum StatusCobranca: string
{
    /** Linha criada localmente, aguardando confirmação do provedor. */
    case Pendente = 'pending';

    /** Provedor confirmou: tem QR/linha digitável, aguardando pagamento. */
    case Registrada = 'registered';

    case Paga = 'paid';

    case ParcialmentePaga = 'partially_paid';

    /** BR-510: cancelada não baixa o título; ele permanece em aberto. */
    case Cancelada = 'cancelled';

    /** BR-510: vencida não baixa o título; ele permanece no aging. */
    case Vencida = 'expired';

    /** Emissão falhou no provedor — motivo em `failure_reason`. */
    case Falhou = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Aguardando emissão',
            self::Registrada => 'Registrada',
            self::Paga => 'Paga',
            self::ParcialmentePaga => 'Paga parcialmente',
            self::Cancelada => 'Cancelada',
            self::Vencida => 'Vencida',
            self::Falhou => 'Falhou',
        };
    }

    /** Nenhuma ação de emissão/cancelamento cabe depois destes estados. */
    public function terminal(): bool
    {
        return match ($this) {
            self::Paga, self::Cancelada, self::Vencida, self::Falhou => true,
            default => false,
        };
    }
}
