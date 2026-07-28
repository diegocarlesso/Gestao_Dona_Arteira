<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Services\ReserveStockService;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Events\OrderCancelled;
use App\Modules\Sales\Exceptions\PedidoInvalido;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * Cancela o pedido e libera o que estava reservado — BR-203/BR-303.
 *
 * Rascunho cancela direto (nunca reservou). Confirmado libera as reservas
 * ativas dele antes — senão a peça ficaria presa a um pedido morto,
 * indisponível para vender de verdade.
 *
 * **Sempre com motivo** (pasta 10 §8): cancelamento alimenta o relatório
 * de causas, e "cancelado sem motivo" não ensina nada a ninguém.
 */
class CancelOrderService
{
    public function __construct(private readonly ReserveStockService $reservas) {}

    /**
     * @throws PedidoInvalido
     */
    public function handle(Order $pedido, string $motivo, ?int $canceladoPor = null): Order
    {
        if (! $pedido->status->cancelavel()) {
            throw PedidoInvalido::transicaoInvalida($pedido->status->label(), OrderStatus::Cancelled->label());
        }

        return DB::transaction(function () use ($pedido, $motivo, $canceladoPor): Order {
            // O Estoque solta o que este pedido segurava. Vendas não
            // consulta `StockReservation` (ADR-0020) — pede pela origem e
            // o Estoque cuida. Rascunho não tem reserva; a chamada devolve
            // zero e segue.
            $this->reservas->liberarPorReferencia('order', $pedido->id);

            OrderStatusHistory::query()->create([
                'order_id' => $pedido->id,
                'from_status' => $pedido->status,
                'to_status' => OrderStatus::Cancelled,
                'reason' => $motivo,
                'created_by' => $canceladoPor,
            ]);

            $pedido->forceFill([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => $motivo,
            ])->save();

            OrderCancelled::dispatch($pedido);

            return $pedido;
        });
    }
}
