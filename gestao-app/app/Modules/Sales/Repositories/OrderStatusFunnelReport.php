<?php

declare(strict_types=1);

namespace App\Modules\Sales\Repositories;

use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Models\Order;

/**
 * "O que está travado no funil?" — relatório da pasta 20 §3.6.
 *
 * Contagem, não dinheiro — `COUNT(*)` via SQL não tem o problema de
 * precisão do `sum()` de coluna monetária (ADR-0013 é sobre valor, não
 * quantidade de linhas).
 */
class OrderStatusFunnelReport
{
    /**
     * @return list<array{status: string, status_label: string, pedidos: int}>
     */
    public function contagens(): array
    {
        $porStatus = Order::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return array_map(
            static fn (OrderStatus $status): array => [
                'status' => $status->value,
                'status_label' => $status->label(),
                'pedidos' => (int) ($porStatus[$status->value] ?? 0),
            ],
            OrderStatus::cases(),
        );
    }
}
