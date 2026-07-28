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
 * Devolve ao Woo o status e o rastreio de um pedido expedido — docs/16 §4,
 * sync-pedidos §7, ADR-0007.
 *
 * A **saída** do fluxo de pedidos: quando a expedição do ERP consome a
 * reserva (`OrderShipped`), o site precisa saber que o pedido saiu e com
 * qual rastreio. Assíncrono de propósito (BR-705) — a loja em hosting
 * compartilhado é lenta e pode estar fora; falhar aqui não desfaz a
 * expedição, a fila tenta de novo.
 *
 * Recebe **primitivos**, não o `Order`: quem dispara é a Integração, que
 * não enxerga `Sales\Models` (ADR-0020).
 *
 * **Rastreio como nota ao cliente**, não campo próprio: o plugin de
 * rastreio da loja é pendência de inventário (docs/16/01), e a nota ao
 * cliente é o caminho nativo do Woo que não depende de plugin.
 */
class PushShipmentToWoo implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public int $orderId,
        public ?string $trackingCode = null,
        public ?string $carrier = null,
    ) {}

    /**
     * Espera crescente: 10s, 30s, 1min, 2min — o site pode estar só lento.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(Client $client): void
    {
        if (! $client->isEnabled()) {
            // Integração desligada: nada a devolver. Nasce assim para não
            // disparar contra produção de quem só roda os testes.
            return;
        }

        $wooId = IntegrationMapping::remoteDe('order', $this->orderId);

        if ($wooId === null) {
            // Pedido sem contraparte no Woo (balcão/atacado) — não há para
            // onde devolver. A guarda de canal no listener já filtra isto;
            // esta é a rede a mais, para o job ser seguro sozinho.
            return;
        }

        // Status → completed (de-para docs/16/01): "expedido" do ERP é
        // "concluído" para o site.
        $client->put("orders/{$wooId}", ['status' => 'completed']);

        if ($this->trackingCode !== null && $this->trackingCode !== '') {
            $rastreio = "Pedido expedido. Código de rastreio: {$this->trackingCode}";

            if ($this->carrier !== null && $this->carrier !== '') {
                $rastreio .= " ({$this->carrier})";
            }

            $client->post("orders/{$wooId}/notes", [
                'note' => $rastreio,
                'customer_note' => true,
            ]);
        }
    }
}
