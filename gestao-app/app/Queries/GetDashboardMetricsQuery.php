<?php

declare(strict_types=1);

namespace App\Queries;

use App\Modules\Integrations\WooCommerce\Models\ImportBatch;
use App\Modules\Integrations\WooCommerce\Models\WooWebhookEvent;
use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class GetDashboardMetricsQuery
{
    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        return Cache::remember('dashboard_metrics', 60, function () {
            return [
                'vendas' => $this->getVendasMetrics(),
                'funil' => $this->getFunilMetrics(),
                'fulfillment' => $this->getFulfillmentMetrics(),
                'sync' => $this->getSyncMetrics(),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function getVendasMetrics(): array
    {
        $hoje = Carbon::today();
        $mes = Carbon::now()->startOfMonth();

        // A regra do glossário (e do Gate 02) dita: "Σ pedidos confirmados no período, cancelados excluídos".
        // Isso quer dizer todos os pedidos que não estão cancelados, ou apenas os confirmados?
        // "Σ pedidos confirmados no período (cancelados excluídos)"
        // Em um sistema, "confirmed" (ou em diante) contam como venda. Rascunhos e Cancelados não.
        $statusValidos = [
            OrderStatus::Confirmed->value,
            OrderStatus::Separando->value,
            OrderStatus::Embalado->value,
            OrderStatus::Expedido->value,
            OrderStatus::Entregue->value,
        ];

        // Dia
        $vendasDiaQuery = Order::query()
            ->whereIn('status', $statusValidos)
            ->whereDate('created_at', $hoje);

        // Mês
        $vendasMesQuery = Order::query()
            ->whereIn('status', $statusValidos)
            ->where('created_at', '>=', $mes);

        $vendasDia = (clone $vendasDiaQuery)->sum('total');
        $pedidosDia = (clone $vendasDiaQuery)->count();
        $ticketMedioDia = $pedidosDia > 0 ? $vendasDia / $pedidosDia : 0;

        $vendasMes = (clone $vendasMesQuery)->sum('total');
        $pedidosMes = (clone $vendasMesQuery)->count();
        $ticketMedioMes = $pedidosMes > 0 ? $vendasMes / $pedidosMes : 0;

        // Por Canal (Mês)
        $porCanal = [];
        foreach (OrderChannel::cases() as $channel) {
            $vendaCanal = (clone $vendasMesQuery)->where('channel', $channel->value)->sum('total');
            $porCanal[$channel->value] = $vendaCanal;
        }

        return [
            'dia' => [
                'total' => $vendasDia,
                'pedidos' => $pedidosDia,
                'ticket_medio' => $ticketMedioDia,
            ],
            'mes' => [
                'total' => $vendasMes,
                'pedidos' => $pedidosMes,
                'ticket_medio' => $ticketMedioMes,
                'por_canal' => $porCanal,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getFunilMetrics(): array
    {
        $statusCounts = Order::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return [
            OrderStatus::Draft->value => $statusCounts[OrderStatus::Draft->value] ?? 0,
            OrderStatus::Confirmed->value => $statusCounts[OrderStatus::Confirmed->value] ?? 0,
            OrderStatus::Separando->value => $statusCounts[OrderStatus::Separando->value] ?? 0,
            OrderStatus::Embalado->value => $statusCounts[OrderStatus::Embalado->value] ?? 0,
            OrderStatus::Expedido->value => $statusCounts[OrderStatus::Expedido->value] ?? 0,
            OrderStatus::Entregue->value => $statusCounts[OrderStatus::Entregue->value] ?? 0,
            OrderStatus::Cancelled->value => $statusCounts[OrderStatus::Cancelled->value] ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getFulfillmentMetrics(): array
    {
        $statusCounts = Order::query()
            ->selectRaw('status, COUNT(*) as total')
            ->whereIn('status', [
                OrderStatus::Confirmed->value, // A separar (aguardando separação)
                OrderStatus::Separando->value, // Em separação
                OrderStatus::Embalado->value,  // Embalado (aguardando expedir)
            ])
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        // Considerando "a separar" como confirmed, ou já "separando" como "a embalar"?
        // A regra diz: "Quantos a separar / a embalar / a expedir".
        // Confirmado -> A separar
        // Separando -> A embalar
        // Embalado -> A expedir
        return [
            'a_separar' => $statusCounts[OrderStatus::Confirmed->value] ?? 0,
            'a_embalar' => $statusCounts[OrderStatus::Separando->value] ?? 0,
            'a_expedir' => $statusCounts[OrderStatus::Embalado->value] ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getSyncMetrics(): array
    {
        // Saúde da sync: pendências + rejeitados + resultado da última reconciliação
        $eventos = WooWebhookEvent::query()
            ->selectRaw('
                COUNT(CASE WHEN signature_valid = 0 THEN 1 END) as rejeitados,
                COUNT(CASE WHEN error IS NOT NULL AND processed_at IS NULL THEN 1 END) as falhas,
                COUNT(CASE WHEN error IS NOT NULL AND processed_at IS NOT NULL THEN 1 END) as pendencias,
                COUNT(CASE WHEN processed_at IS NULL AND error IS NULL AND signature_valid = 1 THEN 1 END) as na_fila
            ')
            ->first();

        $ultimaReconciliacao = ImportBatch::query()
            ->latest('id')
            ->first();

        return [
            'pendencias' => ($eventos->pendencias ?? 0) + ($eventos->falhas ?? 0) + ($eventos->na_fila ?? 0),
            'rejeitados' => $eventos->rejeitados ?? 0,
            'ultima_reconciliacao' => $ultimaReconciliacao ? [
                'status' => $ultimaReconciliacao->status,
                'data' => $ultimaReconciliacao->started_at->format('d/m/Y H:i'),
                'falhas' => $ultimaReconciliacao->failed,
            ] : null,
        ];
    }
}
