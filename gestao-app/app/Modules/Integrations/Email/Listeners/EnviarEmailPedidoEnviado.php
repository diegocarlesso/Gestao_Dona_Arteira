<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Email\Listeners;

use App\Modules\Integrations\Email\Notifications\PedidoEnviadoNotification;
use App\Modules\Sales\Events\OrderShipped;
use Illuminate\Support\Facades\Notification;

/**
 * Ouve a expedição e dispara o e-mail de rastreio — docs/15, BR-310.
 *
 * Mesmo raciocínio de `EnviarEmailPedidoConfirmado`: enxerga só o
 * **Event** de Vendas, nunca `Order`/`Customer` (fronteira verificada no
 * CI); cliente sem e-mail é silêncio, não falha.
 */
class EnviarEmailPedidoEnviado
{
    public function handle(OrderShipped $event): void
    {
        $pedido = $event->pedido;
        $email = $pedido->customer?->email;

        if ($email === null) {
            return;
        }

        Notification::route('mail', $email)->notify(new PedidoEnviadoNotification(
            numeroPedido: (string) $pedido->number,
            nomeCliente: $pedido->customer->name,
            codigoRastreio: $pedido->tracking_code,
            transportadora: $pedido->carrier,
        ));
    }
}
