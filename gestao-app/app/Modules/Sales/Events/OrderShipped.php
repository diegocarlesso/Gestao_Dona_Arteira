<?php

declare(strict_types=1);

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pedido expedido, reserva consumida (baixa no ledger) — BR-303, docs/10 §5.
 *
 * Superfície pública do módulo (ADR-0020). É o gancho da **saída** ao Woo:
 * a Integração ouve isto para devolver status + rastreio ao site (corte 4,
 * quando ativado) — e o financeiro/relatórios de expedição também. Vendas
 * não conhece nenhum desses.
 */
class OrderShipped
{
    use Dispatchable;

    public function __construct(public readonly Order $pedido) {}
}
