<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Jobs;

use App\Modules\Integrations\WooCommerce\Client;
use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Devolve ao Woo o cancelamento de um pedido do site — docs/16 §4,
 * sync-pedidos §7, ADR-0007.
 *
 * Gêmeo do `PushShipmentToWoo`: cancelou no ERP, o site precisa saber.
 * Assíncrono (BR-705); recebe primitivos, não `Order` (ADR-0020). O motivo
 * vira **nota interna** (não ao cliente) — o "por quê" do cancelamento é
 * do operador; o cliente já é avisado pela mudança de status do Woo.
 */
class PushCancellationToWoo implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public int $orderId,
        public ?string $motivo = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(Client $client): void
    {
        if (! $client->isEnabled()) {
            return;
        }

        $wooId = IntegrationMapping::remoteDe('order', $this->orderId);

        if ($wooId === null) {
            // Pedido de balcão/atacado — não existe no Woo.
            return;
        }

        $client->put("orders/{$wooId}", ['status' => 'cancelled']);

        if ($this->motivo !== null && $this->motivo !== '') {
            $client->post("orders/{$wooId}/notes", [
                'note' => "Cancelado no ERP. Motivo: {$this->motivo}",
                'customer_note' => false,
            ]);
        }
    }
}
