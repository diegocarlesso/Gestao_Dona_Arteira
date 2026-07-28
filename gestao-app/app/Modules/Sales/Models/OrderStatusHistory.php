<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Sales\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma transição de estado do pedido — BR-303. Append-only.
 *
 * @property int $id
 * @property int $order_id
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property string|null $reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class OrderStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'order_status_history';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'reason',
        'created_by',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Trilha não se edita nem se apaga — igual à linha de preço e ao
        // movimento de estoque.
        static::updating(static fn (): bool => false);
        static::deleting(static fn (): bool => false);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
