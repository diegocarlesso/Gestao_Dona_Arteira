<?php

declare(strict_types=1);

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pedido confirmado, estoque reservado — BR-203/BR-303.
 *
 * Superfície pública do módulo (ADR-0020). Quem reage — publicar o novo
 * disponível no site (BR-204), avisar o financeiro, o painel de pedidos —
 * ouve isto; Vendas não precisa conhecer nenhum deles.
 */
class OrderConfirmed
{
    use Dispatchable;

    public function __construct(public readonly Order $pedido) {}
}
