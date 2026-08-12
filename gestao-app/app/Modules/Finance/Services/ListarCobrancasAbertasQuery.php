<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\StatusCobranca;
use App\Modules\Finance\Models\BillingCharge;

/**
 * As cobranças que ainda podem mudar de estado no provedor — insumo da
 * reconciliação periódica (docs/12-Financeiro/01-cobranca-e-boletos.md §6:
 * "a consulta é a garantia, o webhook é otimização").
 *
 * Devolve `array`, nunca o Model: quem chama (`Integrations\MercadoPago`)
 * não pode enxergar `App\Modules\Finance\Models\*` (ADR-0020).
 */
class ListarCobrancasAbertasQuery
{
    /**
     * @return list<array{public_id: string, provider_charge_id: string}>
     */
    public function handle(): array
    {
        return BillingCharge::query()
            ->whereIn('status', [StatusCobranca::Pendente->value, StatusCobranca::Registrada->value])
            ->whereNotNull('provider_charge_id')
            ->get(['public_id', 'provider_charge_id'])
            ->map(fn (BillingCharge $cobranca): array => [
                'public_id' => $cobranca->public_id,
                'provider_charge_id' => (string) $cobranca->provider_charge_id,
            ])
            ->all();
    }
}
