<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use Brick\Money\Money;
use Database\Factories\Purchasing\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Um pedido de compra — BR-402, docs/11-Compras (Fase 1).
 *
 * Auditado pelo mesmo motivo do pedido de venda e do título: confirmá-lo
 * cria dívida. A trilha de estados (`purchase_order_status_history`) diz
 * quem mandou e quem confirmou; a auditoria diz quem mexeu no valor antes
 * disso.
 *
 * Totais em **string**, nunca float (ADR-0013). Para somar,
 * `bcadd`/`bcsub`/`bccomp` ou o `money()` abaixo.
 *
 * @property int $id
 * @property string $public_id
 * @property int $number
 * @property int $supplier_id
 * @property PurchaseOrderStatus $status
 * @property string $subtotal
 * @property string $freight
 * @property string $total
 * @property Carbon|null $issued_at
 * @property Carbon|null $expected_delivery_at
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class PurchaseOrder extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'supplier_id',
        'status',
        'subtotal',
        'freight',
        'total',
        'issued_at',
        'expected_delivery_at',
        'notes',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'issued_at' => 'date',
            'expected_delivery_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $pedido): void {
            $pedido->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * @return HasMany<PurchaseOrderStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(PurchaseOrderStatusHistory::class);
    }

    public function money(): Money
    {
        return Money::of($this->total, 'BRL');
    }

    /**
     * O próximo número sequencial — mesma regra do pedido de venda: a
     * corrida é improvável no volume da loja (poucos PCs por semana), e o
     * índice único em `number` arbitra se acontecer.
     */
    public static function proximoNumero(): int
    {
        return ((int) self::query()->max('number')) + 1;
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeComStatus(Builder $query, PurchaseOrderStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }
}
