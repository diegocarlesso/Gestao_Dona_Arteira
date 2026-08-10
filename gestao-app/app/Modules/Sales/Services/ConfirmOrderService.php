<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Exceptions\ReservaInvalida;
use App\Modules\Inventory\Services\ReserveStockService;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Events\OrderConfirmed;
use App\Modules\Sales\Exceptions\PedidoInvalido;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * Confirma o pedido e reserva o estoque — BR-203/BR-303.
 *
 * A transição onde o pedido deixa de ser intenção e passa a segurar peça:
 * cada item reserva do estoque (via `ReserveStockService`), e se qualquer
 * um não couber no disponível, **nada** é reservado — a transação volta
 * inteira e o pedido continua rascunho. Meia-reserva seria pior que
 * nenhuma: prometeria parte de um pedido que não pode ser cumprido.
 *
 * A reserva sai do **local padrão**. Multi-local é evolução (pasta 09
 * §10); enquanto há um ateliê só, escolher local a cada item seria
 * perguntar o óbvio.
 *
 * **`config('sales.require_stock_reservation')` desligado**: confirma sem
 * reservar nada (decisão da diretoria, 2026-08-10 — validar o fluxo de
 * pedidos antes de existir contagem física real). O pedido sai
 * `Confirmed` normalmente, `FulfillmentService::expedir()` só encontra
 * zero reserva ativa para consumir e segue (já tolera isso). Voltar a
 * flag para `true` antes do cutover — sem ela, BR-204 (disponível
 * publicado no site) e a proteção contra oversell não têm o que proteger.
 */
class ConfirmOrderService
{
    public function __construct(private readonly ReserveStockService $reservas) {}

    /**
     * @throws PedidoInvalido
     * @throws ReservaInvalida
     */
    public function handle(Order $pedido, ?int $confirmadoPor = null): Order
    {
        if (! $pedido->status->rascunho()) {
            throw PedidoInvalido::transicaoInvalida($pedido->status->label(), OrderStatus::Confirmed->label());
        }

        $itens = $pedido->items()->get();

        if ($itens->isEmpty()) {
            throw PedidoInvalido::semItens();
        }

        return DB::transaction(function () use ($pedido, $itens, $confirmadoPor): Order {
            if (config('sales.require_stock_reservation', true)) {
                foreach ($itens as $item) {
                    // Sem local: a reserva resolve o padrão por dentro do
                    // Estoque (ADR-0020 — Vendas não conhece `Location`). Lança
                    // ReservaInvalida se faltar disponível, derrubando a
                    // transação inteira — o pedido não confirma pela metade.
                    $this->reservas->reservar(
                        productId: $item->product_id,
                        qty: $item->qty,
                        referenceType: 'order',
                        referenceId: $pedido->id,
                        createdBy: $confirmadoPor,
                    );
                }
            }

            $this->transicionar($pedido, OrderStatus::Confirmed, $confirmadoPor);
            $pedido->forceFill(['confirmed_at' => now()])->save();

            OrderConfirmed::dispatch($pedido);

            return $pedido;
        });
    }

    private function transicionar(Order $pedido, OrderStatus $novo, ?int $usuario): void
    {
        OrderStatusHistory::query()->create([
            'order_id' => $pedido->id,
            'from_status' => $pedido->status,
            'to_status' => $novo,
            'created_by' => $usuario,
        ]);

        $pedido->status = $novo;
        $pedido->save();
    }
}
