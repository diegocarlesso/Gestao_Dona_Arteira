<?php

declare(strict_types=1);

namespace App\Modules\Sales\Repositories;

use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Quanto vendemos, onde?" — relatório da pasta 20 §3.5.
 *
 * `STATUS_VENDA` é a mesma definição canônica de "venda" do glossário do
 * dashboard (pasta 21 §2: "Σ pedidos confirmados no período, cancelados
 * excluídos") — divergir dessa lista entre relatórios é bug, por isso a
 * constante é pública e citável, não um array solto redigitado aqui.
 *
 * `bcadd` em loop, nunca `sum()` do query builder — dinheiro nunca passa
 * por agregação SQL que devolveria float (ADR-0013).
 */
class SalesByPeriodReport
{
    /** @var list<string> */
    public const STATUS_VENDA = [
        OrderStatus::Confirmed->value,
        OrderStatus::Separando->value,
        OrderStatus::Embalado->value,
        OrderStatus::Expedido->value,
        OrderStatus::Entregue->value,
    ];

    /**
     * @return list<array{canal: string, canal_label: string, pedidos: int, total: string, ticket_medio: string}>
     */
    public function porCanal(Carbon $de, Carbon $ate): array
    {
        $pedidos = $this->pedidos($de, $ate);

        return array_map(
            fn (OrderChannel $canal): array => $this->linha($canal->value, $canal->label(), $pedidos->where('channel', $canal)),
            OrderChannel::cases(),
        );
    }

    /**
     * @return array{pedidos: int, total: string, ticket_medio: string}
     */
    public function totalGeral(Carbon $de, Carbon $ate): array
    {
        $linha = $this->linha('geral', 'Geral', $this->pedidos($de, $ate));

        return ['pedidos' => $linha['pedidos'], 'total' => $linha['total'], 'ticket_medio' => $linha['ticket_medio']];
    }

    /**
     * @param  Collection<int, Order>  $pedidos
     * @return array{canal: string, canal_label: string, pedidos: int, total: string, ticket_medio: string}
     */
    private function linha(string $chave, string $label, Collection $pedidos): array
    {
        $qtd = $pedidos->count();
        $total = $pedidos->reduce(static fn (string $acc, Order $p): string => bcadd($acc, $p->total, 2), '0.00');

        return [
            'canal' => $chave,
            'canal_label' => $label,
            'pedidos' => $qtd,
            'total' => $total,
            'ticket_medio' => $qtd > 0 ? bcdiv($total, (string) $qtd, 2) : '0.00',
        ];
    }

    /**
     * @return Collection<int, Order>
     */
    private function pedidos(Carbon $de, Carbon $ate): Collection
    {
        return Order::query()
            ->whereIn('status', self::STATUS_VENDA)
            ->whereBetween('created_at', [$de->copy()->startOfDay(), $ate->copy()->endOfDay()])
            ->get(['id', 'channel', 'total']);
    }
}
