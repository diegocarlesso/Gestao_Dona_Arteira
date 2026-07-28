<?php

declare(strict_types=1);

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pedido cancelado, reserva liberada — BR-203/BR-303.
 *
 * Superfície pública do módulo (ADR-0020). Quem reage — a Integração
 * devolvendo `cancelled` ao Woo (sync-pedidos §7), o financeiro estornando
 * — ouve isto. Vendas não conhece nenhum deles.
 *
 * Hoje todo cancelamento nasce no ERP (`CancelOrderService`); quando
 * existir o caminho de cancelar vindo do site, o anti-eco terá de impedir
 * que esse cancelamento seja empurrado de volta ao Woo.
 */
class OrderCancelled
{
    use Dispatchable;

    public function __construct(public readonly Order $pedido) {}
}
