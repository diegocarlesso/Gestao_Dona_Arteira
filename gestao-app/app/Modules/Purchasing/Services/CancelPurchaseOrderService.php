<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Exceptions\PedidoDeCompraInvalido;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\Concerns\TransicionaPedidoDeCompra;
use Illuminate\Support\Facades\DB;

/**
 * Cancela um pedido de compra ainda não confirmado — docs/11-Compras §3.
 *
 * **Só rascunho e enviado.** Enquanto o PC é intenção, cancelar não custa
 * nada: nenhuma dívida foi criada e nenhuma peça entrou (o recebimento é a
 * Fase 2). Depois de confirmado ele já gerou conta a pagar (BR-402), e
 * cancelar aqui teria de decidir o que fazer com o título — o que exige a
 * contrapartida de estorno do Financeiro (BR-504), que é Gate 04.
 *
 * **Limitação assumida desta fase**: desfazer uma compra confirmada é
 * combinado com o fornecedor por fora e registrado quando o estorno
 * existir. Preferimos a operação sabendo que não pode, a um cancelamento
 * que sumisse com o pedido e deixasse a dívida — ou pior, sumisse com os
 * dois e o dono deixasse de pagar o que deve.
 */
class CancelPurchaseOrderService
{
    use TransicionaPedidoDeCompra;

    /**
     * @throws PedidoDeCompraInvalido
     */
    public function handle(PurchaseOrder $pedido, string $motivo, ?int $por = null): PurchaseOrder
    {
        if ($pedido->status === PurchaseOrderStatus::Confirmed) {
            throw PedidoDeCompraInvalido::confirmadoNaoCancela($pedido->number);
        }

        if (! $pedido->status->cancelavel()) {
            throw PedidoDeCompraInvalido::transicaoInvalida(
                $pedido->status->label(),
                PurchaseOrderStatus::Cancelled->label(),
            );
        }

        return DB::transaction(function () use ($pedido, $motivo, $por): PurchaseOrder {
            $this->transicionar($pedido, PurchaseOrderStatus::Cancelled, $por, $motivo);

            return $pedido;
        });
    }
}
