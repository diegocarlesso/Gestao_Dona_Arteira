<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\FinanceCategoryType;
use App\Modules\Finance\Enums\StatusCobranca;
use App\Modules\Finance\Models\BillingCharge;
use App\Modules\Finance\Models\BillingChargeEvent;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processa uma notificação do provedor sobre uma cobrança — BR-507,
 * docs/12-Financeiro/01-cobranca-e-boletos.md §3/§6.
 *
 * **A superfície pública que `Integrations\MercadoPago` chama** (ADR-0020):
 * só tipos e escalares entram aqui, nunca um payload cru do provedor — a
 * tradução (o que "approved"/"in_process"/"cancelled" do Mercado Pago
 * significa) já aconteceu no adapter de lá (Mappers/StatusMap, docs/15 §3)
 * antes de chegar. Chamado tanto pelo webhook quanto pela reconciliação
 * periódica (a consulta é a garantia; o webhook é só latência baixa).
 *
 * **Idempotência por `provider_event_id`** (BR-507): a constraint UNIQUE
 * de `billing_charge_events` é quem garante, de verdade, que o mesmo
 * evento reentregue não baixa duas vezes — o `exists()` antes é só o
 * caminho feliz mais rápido, não a garantia (por isso o `try/catch` ao
 * redor do `create()` continua necessário).
 */
class ProcessarNotificacaoCobrancaService
{
    public const CONTA_MERCADO_PAGO = 'Mercado Pago';

    public const CATEGORIA_TAXA = 'Taxas de gateway/marketplace';

    public function __construct(
        private readonly RegisterSettlementService $baixas,
        private readonly RegisterPayableService $payables,
    ) {}

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function handle(
        string $providerChargeId,
        ?string $providerEventId,
        string $eventType,
        StatusCobranca $status,
        ?string $paidAmount,
        ?string $feeAmount,
        ?Carbon $paidAt,
        array $rawPayload,
    ): void {
        $cobranca = BillingCharge::query()->where('provider_charge_id', $providerChargeId)->first();

        if ($cobranca === null) {
            Log::error('Financeiro: notificação de cobrança para provider_charge_id desconhecido.', [
                'provider_charge_id' => $providerChargeId,
                'event_type' => $eventType,
            ]);

            return;
        }

        if ($providerEventId !== null
            && BillingChargeEvent::query()->where('provider_event_id', $providerEventId)->exists()) {
            // BR-507: evento já processado — reentrega do provedor, no-op.
            return;
        }

        try {
            DB::transaction(function () use ($cobranca, $eventType, $providerEventId, $status, $paidAmount, $feeAmount, $paidAt, $rawPayload): void {
                $evento = BillingChargeEvent::query()->create([
                    'charge_id' => $cobranca->id,
                    'type' => $eventType,
                    'provider_event_id' => $providerEventId,
                    'payload' => $rawPayload,
                ]);

                $this->aplicarStatus($cobranca, $status, $paidAmount, $feeAmount, $paidAt);

                $evento->marcarProcessado();
            });
        } catch (QueryException $e) {
            if ($this->violaUniqueDeEvento($e)) {
                // Corrida entre o webhook e a reconciliação (ou dois
                // webhooks quase simultâneos): o outro processo já
                // inseriu o mesmo `provider_event_id` — a baixa dele já
                // rodou, esta chamada é a duplicata mesmo.
                return;
            }

            throw $e;
        }
    }

    private function aplicarStatus(
        BillingCharge $cobranca,
        StatusCobranca $status,
        ?string $paidAmount,
        ?string $feeAmount,
        ?Carbon $paidAt,
    ): void {
        match ($status) {
            StatusCobranca::Paga, StatusCobranca::ParcialmentePaga => $this->processarPagamento(
                $cobranca,
                $status === StatusCobranca::ParcialmentePaga,
                $paidAmount ?? $cobranca->amount,
                $feeAmount,
                $paidAt ?? now(),
            ),
            // BR-510: cancelada/vencida nunca baixam o título — ele
            // permanece aberto e continua no aging.
            StatusCobranca::Cancelada => $cobranca->cancelar(),
            StatusCobranca::Vencida => $cobranca->expirar(),
            StatusCobranca::Falhou => $cobranca->falhar('Provedor reportou falha após o registro.'),
            StatusCobranca::Pendente, StatusCobranca::Registrada => null,
        };
    }

    /**
     * BR-502/509: baixa o título (total ou parcial conforme o valor pago) e
     * registra a tarifa do provedor como despesa já baixada — "tarifa
     * bancária é despesa registrada na baixa" (docs/12 §3).
     */
    private function processarPagamento(
        BillingCharge $cobranca,
        bool $parcial,
        string $paidAmount,
        ?string $feeAmount,
        Carbon $paidAt,
    ): void {
        $cobranca->pagar($paidAmount, $feeAmount, $paidAt, $parcial);

        $conta = $this->contaMercadoPago();

        if ($conta === null) {
            Log::warning('Financeiro: conta "Mercado Pago" ausente, cobrança paga não baixou o título.', [
                'charge_public_id' => $cobranca->public_id,
                'receivable_id' => $cobranca->receivable_id,
            ]);

            return;
        }

        $this->baixas->handle(
            titleType: 'receivable',
            titleId: $cobranca->receivable_id,
            accountId: $conta->id,
            amount: $paidAmount,
            settledAt: $paidAt->toDateString(),
            notes: 'Mercado Pago — cobrança '.$cobranca->public_id,
        );

        if ($feeAmount !== null && bccomp($feeAmount, '0.00', 2) === 1) {
            $this->registrarTarifa($cobranca, $conta, $feeAmount, $paidAt);
        }
    }

    private function registrarTarifa(BillingCharge $cobranca, FinanceAccount $conta, string $feeAmount, Carbon $paidAt): void
    {
        $categoria = FinanceCategory::query()
            ->doTipo(FinanceCategoryType::Payable)
            ->where('name', self::CATEGORIA_TAXA)
            ->first();

        if ($categoria === null) {
            Log::warning('Financeiro: categoria "Taxas de gateway/marketplace" ausente, tarifa não registrada.', [
                'charge_public_id' => $cobranca->public_id,
            ]);

            return;
        }

        $tarifa = $this->payables->registrar(
            originType: 'billing_charge',
            originId: $cobranca->id,
            supplierName: 'Mercado Pago',
            amount: $feeAmount,
            dueDate: $paidAt->toDateString(),
            categoryId: $categoria->id,
        );

        $this->baixas->handle(
            titleType: 'payable',
            titleId: $tarifa->id,
            accountId: $conta->id,
            amount: $feeAmount,
            settledAt: $paidAt->toDateString(),
            notes: 'Tarifa Mercado Pago — cobrança '.$cobranca->public_id,
        );
    }

    private function contaMercadoPago(): ?FinanceAccount
    {
        return FinanceAccount::query()->where('name', self::CONTA_MERCADO_PAGO)->first();
    }

    private function violaUniqueDeEvento(QueryException $e): bool
    {
        return $e->getCode() === '23000' && str_contains($e->getMessage(), 'provider_event_id');
    }
}
