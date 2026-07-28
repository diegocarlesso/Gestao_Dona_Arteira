<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Jobs;

use App\Modules\Integrations\WooCommerce\Models\WooWebhookEvent;
use App\Modules\Integrations\WooCommerce\Services\ImportWooOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Processa um evento de pedido do Woo já persistido — ADR-0007.
 *
 * O webhook responde 200 na hora e deixa este job fazer o trabalho pesado
 * (casar itens, reservar) fora da conexão HTTP. Falhar aqui não trava a
 * loja (BR-705): a fila tenta de novo com espera crescente, e o erro fica
 * no evento para o painel de integrações.
 */
class ProcessWooOrder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Cinco tentativas antes de desistir — site em hosting compartilhado cai. */
    public int $tries = 5;

    public function __construct(public int $eventId) {}

    /**
     * Espera crescente entre tentativas: 10s, 30s, 1min, 2min. O site pode
     * estar só sobrecarregado, e insistir na hora só piora.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(ImportWooOrder $import): void
    {
        $evento = WooWebhookEvent::query()->find($this->eventId);

        // Já processado (reentrega do webhook depois de concluído) ou sumiu:
        // nada a fazer. A idempotência real é do pedido; esta é só a guarda
        // barata que evita reprocessar à toa.
        if ($evento === null || $evento->processed_at !== null) {
            return;
        }

        try {
            $resultado = $import->handle($evento->payload);
            $evento->marcarProcessado($resultado->motivo);
        } catch (Throwable $e) {
            // Erro inesperado (não a falta de saldo, que o import trata):
            // registra e relança para a fila tentar de novo.
            $evento->registrarErro($e->getMessage());

            throw $e;
        }
    }
}
