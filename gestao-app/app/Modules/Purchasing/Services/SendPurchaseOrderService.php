<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Exceptions\PedidoDeCompraInvalido;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\Concerns\TransicionaPedidoDeCompra;
use Illuminate\Support\Facades\DB;

/**
 * Marca o pedido de compra como enviado ao fornecedor — docs/11-Compras §3.
 *
 * **Não envia nada.** O envio real acontece por WhatsApp ou e-mail, na mão
 * de quem compra (pasta 11 §3: "e-mail/WhatsApp"); o que este Service faz
 * é registrar que aconteceu, com autor e data. Um canal automatizado é
 * evolução — e fingir que existe faria a trilha afirmar um envio que
 * ninguém fez.
 *
 * Carimba `issued_at` se ainda estiver vazio: é a data que o vencimento do
 * título vai contar a partir de (BR-402), e deixá-la em branco empurraria
 * a conta para "hoje" na confirmação, que pode ser dias depois.
 */
class SendPurchaseOrderService
{
    use TransicionaPedidoDeCompra;

    /**
     * @throws PedidoDeCompraInvalido
     */
    public function handle(PurchaseOrder $pedido, ?int $por = null): PurchaseOrder
    {
        $this->exigirStatus($pedido, PurchaseOrderStatus::Draft, PurchaseOrderStatus::Sent);

        return DB::transaction(function () use ($pedido, $por): PurchaseOrder {
            $pedido->issued_at ??= now()->startOfDay();

            $this->transicionar($pedido, PurchaseOrderStatus::Sent, $por);

            return $pedido;
        });
    }
}
