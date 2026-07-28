<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Listeners;

use App\Modules\Integrations\WooCommerce\Jobs\PushCancellationToWoo;
use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Events\OrderCancelled;

/**
 * Ouve o cancelamento no ERP e devolve `cancelled` ao Woo — docs/16 §4.
 *
 * Gêmeo do `EnviarExpedicaoAoWoo`: enxerga só o Event e o Enum de Vendas,
 * nunca `Order` (ADR-0020). Só pedido do site tem contraparte no Woo.
 *
 * Todo cancelamento hoje nasce no ERP, então empurrar de volta é sempre
 * correto. Quando existir cancelamento vindo do site, este listener
 * precisará de uma guarda de origem para não devolver o eco.
 */
class CancelarNoWoo
{
    public function handle(OrderCancelled $event): void
    {
        $pedido = $event->pedido;

        if ($pedido->channel !== OrderChannel::WooCommerce) {
            return;
        }

        PushCancellationToWoo::dispatch($pedido->id, $pedido->cancel_reason);
    }
}
