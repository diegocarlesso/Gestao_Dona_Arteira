<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\DTO\OrderSummary;
use App\Modules\Sales\Models\Order;

/**
 * A porta pela qual os outros módulos perguntam a Vendas quem é um pedido
 * — ADR-0020, mesmo papel do `ProductLookupService` no Catálogo e do
 * `CustomerLookupService` aqui dentro.
 *
 * Existe porque o Fiscal guarda `order_id` como inteiro solto (ADR-0025) e
 * o painel de notas precisa mostrar "pedido #123, de Fulana" sem importar
 * `Sales\Models\Order`.
 */
class OrderLookupService
{
    /**
     * @param  list<int>  $ids
     * @return array<int, OrderSummary>
     */
    public function resumos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Order::query()
            ->with('customer:id,name')
            ->whereIn('id', $ids)
            ->get(['id', 'public_id', 'number', 'channel', 'status', 'customer_id'])
            ->mapWithKeys(fn (Order $o): array => [$o->id => new OrderSummary(
                publicId: $o->public_id,
                number: $o->number,
                channelLabel: $o->channel->label(),
                statusLabel: $o->status->label(),
                customerName: $o->customer?->name,
            )])
            ->all();
    }
}
