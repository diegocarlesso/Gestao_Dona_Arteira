<?php

declare(strict_types=1);

namespace App\Modules\Sales\Listeners;

use App\Modules\Fiscal\Events\InvoiceAuthorized;
use App\Modules\Fiscal\Events\InvoiceRejected;
use App\Modules\Sales\Models\Order;

/**
 * Traz a situação fiscal para dentro do pedido — BR-309, ADR-0025 §3.
 *
 * Vendas precisa responder "posso expedir?" sem consultar a tabela de notas
 * do Fiscal (ADR-0020). O Fiscal anuncia; este listener escreve o fato no
 * `Order`; `FulfillmentService::expedir()` lê dali. O dado é duplicado de
 * propósito — o que Vendas guarda não é "existe a nota número tal", é "esta
 * venda está liberada para sair".
 *
 * Lê **só os primitivos** do evento (`orderId`), nunca a `Invoice`: o
 * `arch()` do CI proíbe Vendas de tocar `Fiscal\Models`, e a proibição só é
 * respeitável porque o evento já traz o id do pedido pronto.
 *
 * Um listener para os dois eventos porque a escrita é a mesma coluna: dois
 * listeners quase idênticos divergiriam no dia em que um campo novo
 * entrasse só num deles.
 */
class MarcarNotaFiscalAutorizada
{
    public function handle(InvoiceAuthorized|InvoiceRejected $event): void
    {
        $pedido = Order::query()->find($event->orderId);

        if ($pedido === null) {
            return;
        }

        $autorizada = $event instanceof InvoiceAuthorized;

        $pedido->forceFill([
            'nfe_status' => $autorizada ? 'authorized' : 'rejected',
            // O carimbo só existe na autorização. Numa rejeição posterior a
            // uma autorização (não deveria acontecer — autorizada é
            // imutável), preservar o carimbo antigo seria mentir sobre o
            // estado atual; por isso vai a nulo junto com o status.
            'invoice_authorized_at' => $autorizada ? now() : null,
        ])->save();
    }
}
