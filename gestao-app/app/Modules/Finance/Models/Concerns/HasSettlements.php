<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models\Concerns;

use App\Modules\Finance\Models\FinanceSettlement;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * O que `Payable` e `Receivable` compartilham — BR-502/BR-504, docs/12 §3.
 *
 * Título a pagar e título a receber têm a mesma máquina de estados
 * (`open`/`partially_settled`/`settled`) e a mesma matemática de baixa —
 * dividir isso em dois models sem compartilhar arriscaria a lógica de
 * dinheiro divergir silenciosamente entre os dois lados. Diferente da
 * duplicação aceita em `ImportWooOrder`/`LoadWooCustomers` (que atravessa
 * módulos): aqui os dois models moram no mesmo módulo, então compartilhar
 * é Eloquent comum, não uma fronteira sendo forçada.
 *
 * @property string $amount
 * @property string $status
 */
trait HasSettlements
{
    public const STATUS_OPEN = 'open';

    public const STATUS_PARTIALLY_SETTLED = 'partially_settled';

    public const STATUS_SETTLED = 'settled';

    /**
     * @return MorphMany<FinanceSettlement, $this>
     */
    public function settlements(): MorphMany
    {
        return $this->morphMany(FinanceSettlement::class, 'settleable');
    }

    public function money(): Money
    {
        return Money::of($this->amount, 'BRL');
    }

    public function aberto(): bool
    {
        return $this->status !== self::STATUS_SETTLED;
    }

    /**
     * A soma das baixas — estornos entram com valor negativo (BR-504) e se
     * cancelam na soma sozinhos, sem precisar de um caso especial aqui.
     */
    public function saldoBaixado(): string
    {
        return $this->settlements()
            ->get(['amount'])
            ->reduce(
                static fn (string $acumulado, FinanceSettlement $baixa): string => bcadd($acumulado, $baixa->amount, 2),
                '0.00',
            );
    }

    public function saldoAberto(): string
    {
        return bcsub($this->amount, $this->saldoBaixado(), 2);
    }

    /**
     * Recalcula `status` a partir do saldo — nunca setado à mão em
     * nenhum outro lugar do código (`RegisterSettlementService`/
     * `ReverseSettlementService` são os únicos que chamam isto).
     */
    public function atualizarStatus(): void
    {
        $saldo = $this->saldoAberto();

        $this->status = match (true) {
            bccomp($saldo, '0.00', 2) <= 0 => self::STATUS_SETTLED,
            bccomp($saldo, $this->amount, 2) === 0 => self::STATUS_OPEN,
            default => self::STATUS_PARTIALLY_SETTLED,
        };

        $this->save();
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAbertos(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_SETTLED);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeDaOrigem(Builder $query, string $originType, int $originId): Builder
    {
        return $query->where('origin_type', $originType)->where('origin_id', $originId);
    }
}
