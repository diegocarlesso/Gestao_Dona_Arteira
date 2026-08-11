<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Brick\Money\Money;
use Database\Factories\Finance\FinanceSettlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A baixa de um título — BR-502/BR-504, docs/12-Financeiro §3.
 *
 * `settleable` é `Payable` ou `Receivable` — os dois únicos tipos que o
 * `RegisterSettlementService`/`ReverseSettlementService` conhecem
 * (`$morphMap` explícito no `FinanceServiceProvider`, não o nome de
 * classe cru — o valor gravado é `'payable'`/`'receivable'`, estável
 * mesmo se a classe for renomeada um dia).
 *
 * Auditada como o título: "quem deu baixa, quando, em qual conta" é
 * pergunta de conferência financeira tão comum quanto "quem mudou o
 * valor do título".
 *
 * @property int $id
 * @property string $public_id
 * @property string $settleable_type
 * @property int $settleable_id
 * @property int $account_id
 * @property string $amount
 * @property Carbon $settled_at
 * @property string|null $notes
 * @property int|null $reversal_of_id
 */
class FinanceSettlement extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<FinanceSettlementFactory> */
    use HasFactory;

    protected $table = 'finance_settlements';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'settleable_type',
        'settleable_id',
        'account_id',
        'amount',
        'settled_at',
        'notes',
        'reversal_of_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settled_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $baixa): void {
            $baixa->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function settleable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<FinanceAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    /**
     * @return BelongsTo<FinanceSettlement, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function estorno(): bool
    {
        return $this->reversal_of_id !== null;
    }

    public function money(): Money
    {
        return Money::of($this->amount, 'BRL');
    }
}
