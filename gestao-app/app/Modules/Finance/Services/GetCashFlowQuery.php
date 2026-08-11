<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use Illuminate\Support\Carbon;

/**
 * Fluxo de caixa — projetado × realizado, docs/12-Financeiro §3.
 *
 * **Projetado** = títulos abertos por `due_date` (a pagar entra negativo,
 * a receber positivo). **Realizado** = baixas por `settled_at` (a mesma
 * baixa já carrega o sinal certo, incluindo estornos negativos — não
 * precisa separar por tipo de título aqui).
 *
 * Tudo em `bcadd`/`bcsub`, nunca `sum()` do query builder — regra 6 do
 * projeto, dinheiro nunca passa por agregação SQL que devolveria float.
 */
class GetCashFlowQuery
{
    /**
     * @return array{
     *     projetado: array{30: string, 60: string, 90: string},
     *     realizado: array{30: string, 60: string, 90: string},
     * }
     */
    public function handle(?Carbon $hoje = null): array
    {
        $hoje = $hoje ?? Carbon::today();

        return [
            'projetado' => [
                30 => $this->projetado($hoje, $hoje->copy()->addDays(30)),
                60 => $this->projetado($hoje, $hoje->copy()->addDays(60)),
                90 => $this->projetado($hoje, $hoje->copy()->addDays(90)),
            ],
            'realizado' => [
                30 => $this->realizado($hoje->copy()->subDays(30), $hoje),
                60 => $this->realizado($hoje->copy()->subDays(60), $hoje),
                90 => $this->realizado($hoje->copy()->subDays(90), $hoje),
            ],
        ];
    }

    private function projetado(Carbon $de, Carbon $ate): string
    {
        $aReceber = Receivable::query()
            ->abertos()
            ->whereBetween('due_date', [$de->toDateString(), $ate->toDateString()])
            ->get(['amount'])
            ->reduce(static fn (string $acc, Receivable $t): string => bcadd($acc, $t->amount, 2), '0.00');

        $aPagar = Payable::query()
            ->abertos()
            ->whereBetween('due_date', [$de->toDateString(), $ate->toDateString()])
            ->get(['amount'])
            ->reduce(static fn (string $acc, Payable $t): string => bcadd($acc, $t->amount, 2), '0.00');

        return bcsub($aReceber, $aPagar, 2);
    }

    private function realizado(Carbon $de, Carbon $ate): string
    {
        // Direto na baixa, filtrando por `settleable_type` (o morphMap,
        // FinanceServiceProvider) — mais simples que ir do título até a
        // baixa, e o mesmo resultado: uma baixa de estorno já entra
        // negativa, sem precisar de um caso especial aqui.
        $baixas = FinanceSettlement::query()
            ->whereBetween('settled_at', [$de->toDateString(), $ate->toDateString()])
            ->get(['settleable_type', 'amount']);

        $recebido = $baixas->where('settleable_type', 'receivable')
            ->reduce(static fn (string $acc, FinanceSettlement $baixa): string => bcadd($acc, $baixa->amount, 2), '0.00');

        $pago = $baixas->where('settleable_type', 'payable')
            ->reduce(static fn (string $acc, FinanceSettlement $baixa): string => bcadd($acc, $baixa->amount, 2), '0.00');

        return bcsub($recebido, $pago, 2);
    }
}
