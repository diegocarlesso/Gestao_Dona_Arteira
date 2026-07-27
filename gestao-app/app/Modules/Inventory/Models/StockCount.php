<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\StockCountStatus;
use Database\Factories\Inventory\StockCountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Uma contagem física — BR-205, docs/09-Estoque §5.
 *
 * @property int $id
 * @property string $public_id
 * @property int $location_id
 * @property StockCountStatus $status
 * @property string|null $notes
 * @property int|null $counted_by
 * @property int|null $approved_by
 * @property Carbon|null $closed_at
 * @property Carbon|null $approved_at
 */
class StockCount extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<StockCountFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'location_id',
        'status',
        'notes',
        'counted_by',
        'approved_by',
        'closed_at',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StockCountStatus::class,
            'closed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $contagem): void {
            $contagem->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<StockCountItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }
}
