<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Repositories\SalesByPeriodReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Quanto vendemos, onde?" — ficha na pasta 20 §3.5.
 *
 * Controller fino (ADR-0015): autoriza, resolve o período, delega ao
 * repository.
 */
class SalesByPeriodReportController extends Controller
{
    public function index(Request $request, SalesByPeriodReport $relatorio): InertiaResponse
    {
        $this->authorize('viewReports', Order::class);

        [$de, $ate] = $this->periodo($request);

        return Inertia::render('sales/reports/by-period', [
            'dataDe' => $de->toDateString(),
            'dataAte' => $ate->toDateString(),
            'porCanal' => $relatorio->porCanal($de, $ate),
            'totalGeral' => $relatorio->totalGeral($de, $ate),
        ]);
    }

    public function export(Request $request, SalesByPeriodReport $relatorio): StreamedResponse
    {
        $this->authorize('viewReports', Order::class);

        [$de, $ate] = $this->periodo($request);
        $linhas = $relatorio->porCanal($de, $ate);

        return response()->streamDownload(function () use ($linhas): void {
            $saida = fopen('php://output', 'w');

            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['Canal', 'Pedidos', 'Total vendido', 'Ticket médio'], ';');

            foreach ($linhas as $linha) {
                fputcsv($saida, [
                    $linha['canal_label'],
                    (string) $linha['pedidos'],
                    $this->emReais($linha['total']),
                    $this->emReais($linha['ticket_medio']),
                ], ';');
            }

            fclose($saida);
        }, 'vendas-por-periodo.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodo(Request $request): array
    {
        $de = $request->string('data_de')->value();
        $ate = $request->string('data_ate')->value();

        return [
            $de !== '' ? Carbon::parse($de) : Carbon::now()->startOfMonth(),
            $ate !== '' ? Carbon::parse($ate) : Carbon::now(),
        ];
    }

    private function emReais(string $valor): string
    {
        return number_format((float) $valor, 2, ',', '');
    }
}
