<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Exceptions\TituloInvalido;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use Illuminate\Support\Facades\DB;

/**
 * Estorna uma baixa — BR-504, docs/12-Financeiro §3.
 *
 * **Nunca `DELETE`.** Cria uma baixa nova, de valor negativo, referenciando
 * a que está desfazendo (`reversal_of_id`) — o título volta a ficar
 * (parcialmente) aberto porque `saldoAberto()` soma todas as baixas, e uma
 * baixa negativa cancela a que ela contesta na própria soma. A trilha de
 * auditoria mostra as duas linhas para sempre: a baixa errada não some do
 * histórico, só deixa de valer.
 */
class ReverseSettlementService
{
    /**
     * @throws TituloInvalido
     */
    public function handle(int $settlementId, string $motivo): FinanceSettlement
    {
        $original = FinanceSettlement::query()->find($settlementId);

        if ($original === null) {
            throw TituloInvalido::tituloInexistente('settlement', $settlementId);
        }

        if ($original->estorno()) {
            throw TituloInvalido::estornoDeEstorno($settlementId);
        }

        return DB::transaction(function () use ($original, $motivo): FinanceSettlement {
            $estorno = FinanceSettlement::query()->create([
                'settleable_type' => $original->settleable_type,
                'settleable_id' => $original->settleable_id,
                'account_id' => $original->account_id,
                'amount' => bcmul($original->amount, '-1', 2),
                'settled_at' => now()->toDateString(),
                'notes' => $motivo,
                'reversal_of_id' => $original->id,
            ]);

            $titulo = $original->settleable;

            if ($titulo instanceof Payable || $titulo instanceof Receivable) {
                $titulo->atualizarStatus();
            }

            return $estorno;
        });
    }
}
