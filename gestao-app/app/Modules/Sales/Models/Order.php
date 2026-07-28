<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Enums\OrderStatus;
use Brick\Money\Money;
use Database\Factories\Sales\OrderFactory;
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
 * Um pedido — BR-303, docs/10-Vendas.
 *
 * Auditado: pedido move dinheiro e reserva estoque, e "quem confirmou,
 * quem cancelou" é pergunta que a operação faz. A trilha de estados
 * (`order_status_history`) registra as transições; a auditoria registra
 * as edições de campo.
 *
 * @property int $id
 * @property string $public_id
 * @property int $number
 * @property OrderChannel $channel
 * @property string|null $channel_order_ref
 * @property int|null $customer_id
 * @property OrderStatus $status
 * @property string $price_list
 * @property string $subtotal
 * @property string $discount
 * @property string $shipping
 * @property string $total
 * @property string|null $notes
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property string|null $tracking_code
 * @property string|null $carrier
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int|null $created_by
 */
class Order extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'channel',
        'channel_order_ref',
        'customer_id',
        'status',
        'price_list',
        'subtotal',
        'discount',
        'shipping',
        'total',
        'notes',
        'confirmed_at',
        'shipped_at',
        'delivered_at',
        'tracking_code',
        'carrier',
        'cancelled_at',
        'cancel_reason',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => OrderChannel::class,
            'status' => OrderStatus::class,
            'confirmed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function money(): Money
    {
        return Money::of($this->total, 'BRL');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeComStatus(Builder $query, OrderStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }
}
