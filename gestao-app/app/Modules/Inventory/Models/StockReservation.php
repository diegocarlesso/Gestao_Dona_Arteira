<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\ReservationStatus;
use Database\Factories\Inventory\StockReservationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Uma reserva de estoque — BR-203.
 *
 * @property int $id
 * @property string $public_id
 * @property int $product_id
 * @property int $location_id
 * @property string $qty
 * @property ReservationStatus $status
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $created_by
 */
class StockReservation extends Model
{
    /** @use HasFactory<StockReservationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'location_id',
        'qty',
        'status',
        'reference_type',
        'reference_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $reserva): void {
            $reserva->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAtivas(Builder $query): Builder
    {
        return $query->where('status', ReservationStatus::Active->value);
    }
}
