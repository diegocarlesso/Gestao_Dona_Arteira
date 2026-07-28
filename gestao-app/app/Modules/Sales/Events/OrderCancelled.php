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
 * `$originadoNoCanal` é o **anti-eco**: quando o cancelamento veio do
 * próprio site (a Integração refletindo um `order.updated`), quem ouve
 * para devolver ao Woo (`CancelarNoWoo`) precisa ficar quieto — senão
 * empurra de volta o que o Woo acabou de mandar.
 */
class OrderCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly Order $pedido,
        public readonly bool $originadoNoCanal = false,
    ) {}
}
