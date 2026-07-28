<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Services\ReserveStockService;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Events\OrderShipped;
use App\Modules\Sales\Exceptions\PedidoInvalido;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * Conduz o pedido pela expedição — BR-303, docs/10-Vendas §5 (corte 3).
 *
 * A parte da máquina de estados depois do Confirmado:
 * `confirmado → separando → embalado → expedido → entregue`. Cada passo é
 * uma ação explícita que valida a etapa anterior e grava a transição em
 * `order_status_history` (com autor), do mesmo jeito que confirmar/cancelar.
 *
 * **A expedição é a única que toca estoque.** Separar e embalar são
 * organização do trabalho — a peça continua reservada, prometida. Expedir é
 * quando ela sai de verdade: consome a reserva (`sale_shipment` baixa o
 * `on_hand` no ledger, via `ReserveStockService`) e dispara `OrderShipped`.
 * Vendas não conhece `StockReservation` (ADR-0020) — pede pela origem.
 *
 * **Sem NF-e e sem Melhor Envio neste corte.** A NF-e antes de expedir
 * (BR-309) é o Gate 05; a etiqueta/frete é a fase 6. Aqui o rastreio e a
 * transportadora entram informados à mão.
 */
class FulfillmentService
{
    public function __construct(private readonly ReserveStockService $reservas) {}

    /**
     * Confirmado → Em separação. Começa o picking.
     *
     * @throws PedidoInvalido
     */
    public function iniciarSeparacao(Order $pedido, ?int $por = null): Order
    {
        $this->exigirStatus($pedido, OrderStatus::Confirmed, OrderStatus::Separando);

        return DB::transaction(function () use ($pedido, $por): Order {
            $this->transicionar($pedido, OrderStatus::Separando, $por);

            return $pedido;
        });
    }

    /**
     * Em separação → Embalado. Checklist de embalagem concluído.
     *
     * @throws PedidoInvalido
     */
    public function embalar(Order $pedido, ?int $por = null): Order
    {
        $this->exigirStatus($pedido, OrderStatus::Separando, OrderStatus::Embalado);

        return DB::transaction(function () use ($pedido, $por): Order {
            $this->transicionar($pedido, OrderStatus::Embalado, $por);

            return $pedido;
        });
    }

    /**
     * Embalado → Expedido. A peça sai: consome a reserva (baixa no ledger),
     * registra rastreio e avisa quem escuta `OrderShipped`.
     *
     * @throws PedidoInvalido
     */
    public function expedir(Order $pedido, ?string $trackingCode = null, ?string $carrier = null, ?int $por = null): Order
    {
        $this->exigirStatus($pedido, OrderStatus::Embalado, OrderStatus::Expedido);

        return DB::transaction(function () use ($pedido, $trackingCode, $carrier, $por): Order {
            // O Estoque baixa o que este pedido segurava — cada reserva ativa
            // vira `sale_shipment`. Sem reserva ativa (não deveria acontecer
            // no Embalado) a chamada devolve zero e segue.
            $this->reservas->consumirPorReferencia('order', $pedido->id, $por);

            $pedido->forceFill([
                'shipped_at' => now(),
                'tracking_code' => $trackingCode,
                'carrier' => $carrier,
            ]);

            $this->transicionar($pedido, OrderStatus::Expedido, $por);

            OrderShipped::dispatch($pedido);

            return $pedido;
        });
    }

    /**
     * Expedido → Entregue. Fecha o ciclo; não toca estoque (a peça já saiu).
     *
     * @throws PedidoInvalido
     */
    public function entregar(Order $pedido, ?int $por = null): Order
    {
        $this->exigirStatus($pedido, OrderStatus::Expedido, OrderStatus::Entregue);

        return DB::transaction(function () use ($pedido, $por): Order {
            $pedido->forceFill(['delivered_at' => now()]);
            $this->transicionar($pedido, OrderStatus::Entregue, $por);

            return $pedido;
        });
    }

    /**
     * @throws PedidoInvalido
     */
    private function exigirStatus(Order $pedido, OrderStatus $esperado, OrderStatus $destino): void
    {
        if ($pedido->status !== $esperado) {
            throw PedidoInvalido::transicaoInvalida($pedido->status->label(), $destino->label());
        }
    }

    private function transicionar(Order $pedido, OrderStatus $novo, ?int $usuario): void
    {
        OrderStatusHistory::query()->create([
            'order_id' => $pedido->id,
            'from_status' => $pedido->status,
            'to_status' => $novo,
            'created_by' => $usuario,
            'created_at' => now(),
        ]);

        $pedido->status = $novo;
        $pedido->save();
    }
}
