<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Database\Factories\Finance\BillingProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Perfil de cobrança (multa, juros, desconto) — BR-508,
 * docs/12-Financeiro/01-cobranca-e-boletos.md §4.
 *
 * Percentuais nascem nulos, sem valor padrão chutado: confirmar com o
 * contador ainda está em aberto (BR-508). Uma `BillingCharge` pode ser
 * emitida sem perfil (`profile_id` nullable) enquanto isso não acontece.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string|null $fine_pct
 * @property string|null $interest_pct_month
 * @property string|null $discount_pct
 * @property int|null $discount_days
 * @property int|null $days_to_due
 * @property string|null $instructions
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 */
class BillingProfile extends Model
{
    /** @use HasFactory<BillingProfileFactory> */
    use HasFactory;

    protected $table = 'billing_profiles';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'fine_pct',
        'interest_pct_month',
        'discount_pct',
        'discount_days',
        'days_to_due',
        'instructions',
        'valid_from',
        'valid_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $perfil): void {
            $perfil->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Vigência ativa hoje — `null` em `valid_from`/`valid_to` é "sem limite"
     * daquele lado, mesmo vocabulário de `tax_profiles`.
     */
    public function vigente(?Carbon $data = null): bool
    {
        $data ??= now();

        if ($this->valid_from !== null && $data->lt($this->valid_from)) {
            return false;
        }

        return $this->valid_to === null || ! $data->gt($this->valid_to);
    }
}
