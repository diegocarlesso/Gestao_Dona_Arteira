<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

/**
 * Resultado de registrar um pedido de canal — a superfície que Vendas
 * devolve a quem chama de fora (ADR-0020).
 *
 * Primitivos, não o model `Order`: o módulo que integra o canal não pode
 * enxergar `Sales\Models` (fronteira verificada no CI). Devolve-se o id e
 * se o pedido já saiu confirmado — o resto quem quiser lê pelo id.
 */
final readonly class ChannelOrderResult
{
    public function __construct(
        public int $orderId,
        public bool $confirmed,
    ) {}
}
