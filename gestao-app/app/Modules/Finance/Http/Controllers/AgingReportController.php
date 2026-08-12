<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Repositories\TitlesAgingReport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Quem nos deve / a quem devemos" com aging — ficha na pasta 20 §3.3.
 *
 * Controller fino (ADR-0015): autoriza, filtra, delega ao repository.
 */
class AgingReportController extends Controller
{
    private const TIPOS = ['receivable', 'payable'];

    public function index(Request $request, TitlesAgingReport $relatorio): InertiaResponse
    {
        $this->authorize('viewReports', Payable::class);

        [$tipo, $dataDe, $dataAte] = $this->filtros($request);

        $linhas = $relatorio->linhas($tipo, $dataDe, $dataAte);

        return Inertia::render('finance/reports/aging', [
            'tipo' => $tipo,
            'dataDe' => $dataDe,
            'dataAte' => $dataAte,
            'titulos' => $this->paraTela($linhas, $tipo, $relatorio),
            'totais' => $relatorio->totaisPorFaixa($linhas),
        ]);
    }

    public function export(Request $request, TitlesAgingReport $relatorio): StreamedResponse
    {
        $this->authorize('viewReports', Payable::class);

        [$tipo, $dataDe, $dataAte] = $this->filtros($request);

        $linhas = $relatorio->linhas($tipo, $dataDe, $dataAte);
        $hoje = now()->startOfDay();

        return response()->streamDownload(function () use ($linhas, $tipo, $relatorio, $hoje): void {
            $saida = fopen('php://output', 'w');

            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, [
                $tipo === 'payable' ? 'Fornecedor' : 'Cliente',
                'Categoria',
                'Vencimento',
                'Faixa',
                'Saldo aberto',
            ], ';');

            foreach ($linhas as $titulo) {
                fputcsv($saida, [
                    $tipo === 'payable' ? $titulo->supplier_name : $titulo->customer_name,
                    $titulo->category->name,
                    $titulo->due_date?->format('d/m/Y') ?? '',
                    $this->faixaLabel($relatorio->faixa($titulo, $hoje)),
                    $this->emReais($titulo->saldoAberto()),
                ], ';');
            }

            fclose($saida);
        }, "aging-{$tipo}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function filtros(Request $request): array
    {
        $tipo = $request->string('tipo')->trim()->value();
        $tipo = in_array($tipo, self::TIPOS, true) ? $tipo : 'receivable';

        return [$tipo, $request->string('data_de')->value() ?: null, $request->string('data_ate')->value() ?: null];
    }

    /**
     * @param  Collection<int, Payable|Receivable>  $linhas
     * @return list<array{public_id: string, nome: string, categoria: string, vencimento: string|null, faixa: string, saldo_aberto: string}>
     */
    private function paraTela(Collection $linhas, string $tipo, TitlesAgingReport $relatorio): array
    {
        $hoje = now()->startOfDay();

        return $linhas->map(fn (Payable|Receivable $titulo): array => [
            'public_id' => $titulo->public_id,
            'nome' => $tipo === 'payable' ? $titulo->supplier_name : $titulo->customer_name,
            'categoria' => $titulo->category->name,
            'vencimento' => $titulo->due_date?->toDateString(),
            'faixa' => $relatorio->faixa($titulo, $hoje),
            'saldo_aberto' => $titulo->saldoAberto(),
        ])->all();
    }

    private function faixaLabel(string $faixa): string
    {
        return match ($faixa) {
            'a_vencer' => 'A vencer',
            '1-15' => 'Vencido 1-15 dias',
            '16-30' => 'Vencido 16-30 dias',
            '30+' => 'Vencido 30+ dias',
            default => $faixa,
        };
    }

    private function emReais(string $valor): string
    {
        return number_format((float) $valor, 2, ',', '');
    }
}
