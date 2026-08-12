<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Services\GetCashFlowQuery;
use App\Modules\Identity\Enums\Permission;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fluxo de caixa — projetado × realizado, docs/12-Financeiro §3,
 * ficha do relatório na pasta 20 §3.4.
 *
 * Já é uma tela só de leitura (sem baixa, sem edição) — vira relatório só
 * com a exportação a mais; não precisou de uma tela/query separada
 * (pasta 20 §2, ponto 2: "sem impacto em telas transacionais", e esta já
 * não tem impacto nenhum).
 */
class CashFlowController extends Controller
{
    public function index(GetCashFlowQuery $fluxo): Response
    {
        $this->authorize('viewAny', Payable::class);

        return Inertia::render('finance/cash-flow/index', [
            'fluxo' => $fluxo->handle(),
            // Exportar é `reports.view`, família própria (pasta 20) — sem
            // isto a tela ofereceria um botão que devolve 403 pra quem só
            // tem `finance.view` (ex.: um papel operacional futuro).
            'podeExportar' => request()->user()?->can(Permission::ReportsView->value) ?? false,
        ]);
    }

    public function export(GetCashFlowQuery $fluxo): StreamedResponse
    {
        $this->authorize('viewReports', Payable::class);

        $dados = $fluxo->handle();

        return response()->streamDownload(function () use ($dados): void {
            $saida = fopen('php://output', 'w');

            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['Janela', 'Projetado (a receber - a pagar)', 'Realizado (baixas)'], ';');

            foreach ([30, 60, 90] as $janela) {
                fputcsv($saida, [
                    "{$janela} dias",
                    $this->emReais($dados['projetado'][$janela]),
                    $this->emReais($dados['realizado'][$janela]),
                ], ';');
            }

            fclose($saida);
        }, 'fluxo-de-caixa.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function emReais(string $valor): string
    {
        return number_format((float) $valor, 2, ',', '');
    }
}
