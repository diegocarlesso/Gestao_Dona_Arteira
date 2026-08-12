<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Repositories\OrderStatusFunnelReport;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "O que está travado no funil?" — ficha na pasta 20 §3.6.
 */
class OrderStatusFunnelReportController extends Controller
{
    public function index(OrderStatusFunnelReport $relatorio): InertiaResponse
    {
        $this->authorize('viewReports', Order::class);

        return Inertia::render('sales/reports/status-funnel', [
            'porStatus' => $relatorio->contagens(),
        ]);
    }

    public function export(OrderStatusFunnelReport $relatorio): StreamedResponse
    {
        $this->authorize('viewReports', Order::class);

        $linhas = $relatorio->contagens();

        return response()->streamDownload(function () use ($linhas): void {
            $saida = fopen('php://output', 'w');

            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['Status', 'Pedidos'], ';');

            foreach ($linhas as $linha) {
                fputcsv($saida, [$linha['status_label'], (string) $linha['pedidos']], ';');
            }

            fclose($saida);
        }, 'funil-de-pedidos.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
