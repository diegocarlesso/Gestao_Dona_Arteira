<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Models;

use App\Modules\Fiscal\Enums\InvoiceStatus;
use Database\Factories\Fiscal\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Uma NF-e — docs/14, ADR-0025.
 *
 * Auditada por obrigação prática: documento fiscal responde a fisco, e a
 * pergunta "quem mexeu nesta nota depois de autorizada" precisa ter
 * resposta mesmo (e principalmente) quando a resposta for "ninguém".
 *
 * **`order_id` é um inteiro, não uma relação.** O banco garante a
 * integridade por FK; o PHP não navega até `Sales\Models\Order` (ADR-0020,
 * verificado no CI). Quando o Fiscal precisa saber algo do pedido, pergunta
 * a `Sales\Services\BuildOrderInvoiceSnapshot`.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int|null $series_id
 * @property int|null $number
 * @property string|null $access_key
 * @property InvoiceStatus $status
 * @property string|null $xml_path
 * @property string|null $protocol
 * @property string|null $rejection_reason
 * @property string $total
 * @property int|null $tax_profile_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Invoice extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'series_id',
        'number',
        'access_key',
        'status',
        'xml_path',
        'protocol',
        'rejection_reason',
        'total',
        'tax_profile_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'number' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $nota): void {
            $nota->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * A série é do próprio módulo — esta relação é interna e legítima.
     *
     * @return BelongsTo<FiscalSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(FiscalSeries::class, 'series_id');
    }

    /**
     * @return BelongsTo<TaxProfile, $this>
     */
    public function taxProfile(): BelongsTo
    {
        return $this->belongsTo(TaxProfile::class, 'tax_profile_id');
    }

    /**
     * Como a operação se refere a esta nota: "NF-e 1/000123".
     *
     * Sem número ainda (pendência de cadastro), mostra o que dá — nunca
     * inventa um número que a SEFAZ não conhece.
     */
    public function identificacao(): string
    {
        if ($this->number === null || $this->series === null) {
            return 'NF-e sem número (pendente)';
        }

        return sprintf('NF-e %s/%06d', $this->series->series, $this->number);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeComStatus(Builder $query, InvoiceStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * As notas que precisam de alguém — o painel fiscal (docs/14 §5).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePendentes(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InvoiceStatus::Pending->value,
            InvoiceStatus::Rejected->value,
        ]);
    }
}
