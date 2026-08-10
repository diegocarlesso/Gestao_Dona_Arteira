<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma transição de estado do pedido de compra — BR-402. Append-only.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property PurchaseOrderStatus|null $from_status
 * @property PurchaseOrderStatus $to_status
 * @property string|null $reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class PurchaseOrderStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'purchase_order_status_history';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'purchase_order_id',
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
            'from_status' => PurchaseOrderStatus::class,
            'to_status' => PurchaseOrderStatus::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Trilha não se edita nem se apaga — igual à do pedido de venda e
        // ao movimento de estoque.
        static::updating(static fn (): bool => false);
        static::deleting(static fn (): bool => false);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
