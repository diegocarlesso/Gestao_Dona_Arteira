<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Contracts\CobrancaGatewayInterface;
use App\Modules\Finance\Enums\StatusCobranca;
use App\Modules\Finance\Exceptions\CobrancaGatewayIndisponivel;
use App\Modules\Finance\Exceptions\CobrancaInvalida;
use App\Modules\Finance\Jobs\ProcessarCancelamentoCobranca;
use App\Modules\Finance\Models\BillingCharge;
use Illuminate\Support\Facades\Log;

/**
 * Cancela uma cobrança pendente — BR-506/510: cobrança registrada é
 * imutável, mudar é cancelar e reemitir; cancelada nunca baixa o título,
 * que permanece aberto e continua no aging.
 */
class CancelarCobrancaService
{
    public function __construct(
        private readonly CobrancaGatewayInterface $gateway,
    ) {}

    /**
     * @throws CobrancaInvalida
     */
    public function solicitar(string $chargePublicId): BillingCharge
    {
        $cobranca = BillingCharge::query()->where('public_id', $chargePublicId)->first();

        if ($cobranca === null) {
            throw CobrancaInvalida::cobrancaInexistente($chargePublicId);
        }

        if ($cobranca->status->terminal()) {
            throw CobrancaInvalida::naoCancelavel($cobranca->status->value);
        }

        // Ainda não foi registrada no provedor (falhou antes de sair
        // localmente, ou o job de emissão nem rodou): não há o que
        // cancelar do outro lado, só marcar aqui.
        if ($cobranca->provider_charge_id === null) {
            $cobranca->cancelar();

            return $cobranca;
        }

        ProcessarCancelamentoCobranca::dispatch($cobranca->public_id);

        return $cobranca;
    }

    /**
     * Roda dentro do job — mesmo acordo de `EmitirCobrancaService::processar()`.
     */
    public function processar(string $chargePublicId): void
    {
        $cobranca = BillingCharge::query()->where('public_id', $chargePublicId)->first();

        if ($cobranca === null || $cobranca->status !== StatusCobranca::Registrada || $cobranca->provider_charge_id === null) {
            return;
        }

        try {
            $this->gateway->cancelar($cobranca->provider_charge_id);
        } catch (CobrancaGatewayIndisponivel $e) {
            Log::warning('Financeiro: cancelamento de cobrança falhou no provedor.', [
                'charge_public_id' => $chargePublicId,
                'motivo' => $e->getMessage(),
            ]);

            return;
        }

        $cobranca->cancelar();
    }
}
