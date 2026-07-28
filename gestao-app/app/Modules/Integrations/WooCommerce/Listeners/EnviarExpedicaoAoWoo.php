<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Listeners;

use App\Modules\Integrations\WooCommerce\Jobs\PushShipmentToWoo;
use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Events\OrderShipped;

/**
 * Ouve a expedição e devolve o status/rastreio ao Woo — docs/16 §4.
 *
 * A camada anticorrupção reagindo a um evento de domínio (ADR-0020): Vendas
 * anuncia `OrderShipped` sem saber que o Woo existe; a Integração ouve e
 * traduz. Enxerga só o **Event** e o **Enum** de Vendas — nunca `Order`
 * (a fronteira é verificada no CI). Lê os primitivos do pedido e delega o
 * trabalho externo ao job, que roda em fila (BR-705).
 *
 * Só pedido do **site** tem contraparte no Woo — a guarda de canal evita
 * empurrar um pedido de balcão para um id que não existe lá.
 */
class EnviarExpedicaoAoWoo
{
    public function handle(OrderShipped $event): void
    {
        $pedido = $event->pedido;

        if ($pedido->channel !== OrderChannel::WooCommerce) {
            return;
        }

        PushShipmentToWoo::dispatch($pedido->id, $pedido->tracking_code, $pedido->carrier);
    }
}
