<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Exceptions\PedidoInvalido;
use App\Modules\Sales\Models\Order;

/**
 * Exclui (soft delete) um pedido cancelado — BR-311.
 *
 * Não é a mesma coisa que cancelar: cancelar é uma transição de estado que
 * qualquer pedido ativo pode sofrer e que libera reserva. Excluir só faz
 * sentido depois — é limpar da lista o que já foi encerrado, e só quando
 * não há nota fiscal envolvida (ela é documento legal, não desaparece).
 */
class DeleteOrderService
{
    /**
     * @throws PedidoInvalido
     */
    public function handle(Order $pedido): void
    {
        if (! $pedido->excluivel()) {
            throw PedidoInvalido::naoExcluivel($pedido->status->label(), $pedido->nfe_status !== null);
        }

        $pedido->delete();
    }
}
