<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Exceptions\TituloInvalido;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use Illuminate\Support\Facades\DB;

/**
 * Dá baixa (total ou parcial) num título — BR-502, docs/12-Financeiro §3.
 *
 * Serve pagável **e** recebível pelo mesmo método: os dois têm a mesma
 * máquina de estados (`HasSettlements`), e separar em dois Services só
 * duplicaria a validação de saldo. `$titleType` usa o mesmo vocabulário do
 * `morphMap` (`FinanceServiceProvider`) — `'payable'` ou `'receivable'`.
 *
 * **Abre a própria transação**, diferente de `RegisterPayableService`/
 * `RegisterReceivableService`: a baixa não nasce dentro de um fluxo maior
 * de outro módulo (Vendas/Compras já commitaram o título antes) — ela é a
 * própria unidade de trabalho.
 */
class RegisterSettlementService
{
    /**
     * @throws TituloInvalido
     */
    public function handle(
        string $titleType,
        int $titleId,
        int $accountId,
        string $amount,
        string $settledAt,
        ?string $notes = null,
    ): FinanceSettlement {
        $titulo = $this->resolverTitulo($titleType, $titleId);
        $conta = FinanceAccount::query()->find($accountId);

        if ($conta === null) {
            throw TituloInvalido::contaInexistente($accountId);
        }

        $valor = $this->dinheiro($amount);

        if (bccomp($valor, '0.00', 2) !== 1) {
            throw TituloInvalido::valorNaoPositivo($valor);
        }

        $saldoAberto = $titulo->saldoAberto();

        // BR-509: pagamento a maior não é baixa parcial de sinal trocado —
        // é decisão de negócio que este Service não toma sozinho.
        if (bccomp($valor, $saldoAberto, 2) === 1) {
            throw TituloInvalido::baixaExcedeSaldo($valor, $saldoAberto);
        }

        return DB::transaction(function () use ($titulo, $titleType, $titleId, $conta, $valor, $settledAt, $notes): FinanceSettlement {
            $baixa = FinanceSettlement::query()->create([
                'settleable_type' => $titleType,
                'settleable_id' => $titleId,
                'account_id' => $conta->id,
                'amount' => $valor,
                'settled_at' => $settledAt,
                'notes' => $notes,
            ]);

            $titulo->atualizarStatus();

            return $baixa;
        });
    }

    /**
     * @throws TituloInvalido
     */
    private function resolverTitulo(string $titleType, int $titleId): Payable|Receivable
    {
        $classe = match ($titleType) {
            'payable' => Payable::class,
            'receivable' => Receivable::class,
            default => throw TituloInvalido::tipoDesconhecido($titleType),
        };

        $titulo = $classe::query()->find($titleId);

        if ($titulo === null) {
            throw TituloInvalido::tituloInexistente($titleType, $titleId);
        }

        return $titulo;
    }

    private function dinheiro(string $valor): string
    {
        return bcadd($valor === '' ? '0' : $valor, '0', 2);
    }
}
