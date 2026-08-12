<?php

declare(strict_types=1);

namespace App\Modules\Finance\Repositories;

use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Quem nos deve / a quem devemos, e há quanto tempo?" — relatório da
 * pasta 20 §3.3.
 *
 * Mesma definição de faixa de aging da tela operacional (`TitleController`)
 * — divergência entre as duas seria bug (pasta 20 §5). A diferença é o
 * propósito: aqui é consulta dedicada, sem paginação e sem baixa, pensada
 * para ser somada e exportada — a tela de `/financeiro` é para agir sobre
 * um título, este relatório é para enxergar o total.
 */
class TitlesAgingReport
{
    public const FAIXAS = ['a_vencer', '1-15', '16-30', '30+'];

    /**
     * @return Collection<int, Payable|Receivable>
     */
    public function linhas(string $tipo, ?string $dataDe = null, ?string $dataAte = null): Collection
    {
        $classe = $tipo === 'payable' ? Payable::class : Receivable::class;

        /** @var Collection<int, Payable|Receivable> */
        return $classe::query()
            ->with('category')
            ->abertos()
            ->when($dataDe !== null, fn ($q) => $q->where('due_date', '>=', $dataDe))
            ->when($dataAte !== null, fn ($q) => $q->where('due_date', '<=', $dataAte))
            ->orderByRaw('due_date IS NULL, due_date ASC')
            ->get();
    }

    /**
     * Soma o saldo aberto (não o valor original) por faixa — `bcadd` em
     * loop, nunca `sum()` do query builder (ADR-0013).
     *
     * @param  Collection<int, Payable|Receivable>  $linhas
     * @return array<string, string>
     */
    public function totaisPorFaixa(Collection $linhas, ?Carbon $hoje = null): array
    {
        $hoje = ($hoje ?? Carbon::today())->startOfDay();

        $totais = array_fill_keys(self::FAIXAS, '0.00');

        foreach ($linhas as $titulo) {
            $faixa = $this->faixa($titulo, $hoje);
            $totais[$faixa] = bcadd($totais[$faixa], $titulo->saldoAberto(), 2);
        }

        $totais['total'] = array_reduce($totais, static fn (string $acc, string $v): string => bcadd($acc, $v, 2), '0.00');

        return $totais;
    }

    public function faixa(Payable|Receivable $titulo, Carbon $hoje): string
    {
        if ($titulo->due_date === null || ! $titulo->due_date->lt($hoje)) {
            return 'a_vencer';
        }

        // `abs()`: o Carbon usado aqui devolve diferença com sinal por
        // padrão — sem isso, todo vencido caía sempre na primeira faixa
        // (número negativo é `<= 15`). Mesma armadilha do TitleController.
        $dias = abs((int) $hoje->diffInDays($titulo->due_date));

        return match (true) {
            $dias <= 15 => '1-15',
            $dias <= 30 => '16-30',
            default => '30+',
        };
    }
}
