<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Models\Concerns\HasSettlements;
use Database\Factories\Finance\ReceivableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Um título a receber — BR-501/BR-502, docs/12-Financeiro §3.
 *
 * Espelho de `Payable` (ver o comentário de `HasSettlements` para o porquê
 * de compartilhar a máquina de estados em vez de duplicá-la).
 *
 * @property int $id
 * @property string $public_id
 * @property int $category_id
 * @property string|null $origin_type
 * @property int|null $origin_id
 * @property string $customer_name
 * @property string $amount
 * @property Carbon|null $due_date
 * @property string $status
 */
class Receivable extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<ReceivableFactory> */
    use HasFactory;

    use HasSettlements;

    protected $table = 'finance_receivables';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'origin_type',
        'origin_id',
        'customer_name',
        'amount',
        'due_date',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $titulo): void {
            $titulo->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<FinanceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    /**
     * @return HasMany<BillingCharge, $this>
     */
    public function billingCharges(): HasMany
    {
        return $this->hasMany(BillingCharge::class, 'receivable_id');
    }

    /**
     * A cobrança mais recente, qualquer que seja o status — a tela mostra
     * "última tentativa" e libera emitir de novo conforme o estado dela
     * (`EmitirCobrancaService::solicitar()` já recusa uma segunda cobrança
     * ativa, então mostrar só a última nunca esconde uma corrente).
     */
    public function cobrancaMaisRecente(): ?BillingCharge
    {
        return $this->billingCharges()->latest('id')->first();
    }
}
