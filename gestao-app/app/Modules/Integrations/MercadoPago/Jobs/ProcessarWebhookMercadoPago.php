<?php

declare(strict_types=1);

namespace App\Modules\Integrations\MercadoPago\Jobs;

use App\Modules\Finance\Services\ProcessarNotificacaoCobrancaService;
use App\Modules\Integrations\MercadoPago\Client;
use App\Modules\Integrations\MercadoPago\Exceptions\IntegracaoMercadoPagoIndisponivel;
use App\Modules\Integrations\MercadoPago\Mappers\StatusMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Busca o estado real de um pagamento e repassa ao Financeiro — ADR-0007,
 * ADR-0018 §3.1, BR-507.
 *
 * O webhook do Mercado Pago não traz o status no corpo — só o `id` do
 * pagamento — então este job sempre faz o GET antes de decidir qualquer
 * coisa. Usado tanto pelo webhook (latência baixa) quanto pela
 * reconciliação periódica (garantia); o efeito é idêntico, só a origem do
 * disparo muda.
 *
 * Dedupe (BR-507) não acontece aqui: `ProcessarNotificacaoCobrancaService`
 * já garante isso via `provider_event_id` UNIQUE — este job só busca e
 * traduz.
 */
class ProcessarWebhookMercadoPago implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public string $paymentId,
        public ?string $providerEventId,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(Client $client, ProcessarNotificacaoCobrancaService $service): void
    {
        try {
            $pagamento = $client->consultarPagamento($this->paymentId);
        } catch (IntegracaoMercadoPagoIndisponivel $e) {
            // Sem credencial configurada, ou a chamada falhou: a
            // reconciliação horária (`ReconciliarCobrancasMercadoPago`)
            // tenta de novo sozinha por conta própria — não vale empilhar
            // retry de fila para o mesmo problema (docs/12-Financeiro/
            // 01-cobranca-e-boletos.md §6: "a consulta é a garantia").
            Log::warning('Financeiro: consulta ao Mercado Pago falhou ao processar notificação de cobrança.', [
                'payment_id' => $this->paymentId,
                'motivo' => $e->getMessage(),
            ]);

            return;
        }

        $service->handle(
            providerChargeId: (string) ($pagamento['id'] ?? $this->paymentId),
            providerEventId: $this->providerEventId,
            eventType: 'payment.updated',
            status: StatusMap::de((string) ($pagamento['status'] ?? 'pending')),
            paidAmount: $this->valorPago($pagamento),
            feeAmount: $this->somarTarifas($pagamento),
            paidAt: isset($pagamento['date_approved']) ? Carbon::parse((string) $pagamento['date_approved']) : null,
            rawPayload: $pagamento,
        );
    }

    /**
     * @param  array<string, mixed>  $pagamento
     */
    private function valorPago(array $pagamento): ?string
    {
        if (! isset($pagamento['transaction_amount'])) {
            return null;
        }

        return bcadd((string) $pagamento['transaction_amount'], '0', 2);
    }

    /**
     * Soma `fee_details[].amount` em string decimal — nunca float
     * (ADR-0013).
     *
     * @param  array<string, mixed>  $pagamento
     */
    private function somarTarifas(array $pagamento): ?string
    {
        /** @var list<array<string, mixed>> $detalhes */
        $detalhes = $pagamento['fee_details'] ?? [];

        if ($detalhes === []) {
            return null;
        }

        $total = '0.00';

        foreach ($detalhes as $item) {
            $total = bcadd($total, (string) ($item['amount'] ?? '0'), 2);
        }

        return bccomp($total, '0.00', 2) === 1 ? $total : null;
    }
}
