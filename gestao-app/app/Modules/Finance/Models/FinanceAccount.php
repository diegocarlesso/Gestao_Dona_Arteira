<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\FinanceAccountType;
use Database\Factories\Finance\FinanceAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Uma conta financeira (caixa, banco, gateway) — docs/12-Financeiro §3.
 *
 * **Sem coluna de saldo** — `saldo()` soma as baixas desta conta, mesmo
 * princípio do saldo de estoque (ADR-0008): número guardado é número que
 * pode dessincronizar do que o gerou.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property FinanceAccountType $type
 * @property string|null $notes
 */
class FinanceAccount extends Model
{
    /** @use HasFactory<FinanceAccountFactory> */
    use HasFactory;

    protected $table = 'finance_accounts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FinanceAccountType::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $conta): void {
            $conta->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return HasMany<FinanceSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(FinanceSettlement::class, 'account_id');
    }

    /**
     * O saldo desta conta — soma de todas as baixas (estornos já entram
     * negativos, BR-504). `bcadd` em loop, não `sum()` do query builder:
     * dinheiro nunca passa por agregação SQL que devolveria float.
     */
    public function saldo(): string
    {
        return $this->settlements()
            ->get(['amount'])
            ->reduce(
                static fn (string $acumulado, FinanceSettlement $baixa): string => bcadd($acumulado, $baixa->amount, 2),
                '0.00',
            );
    }
}
