<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Database\Factories\Inventory\StockCountItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha de contagem: o que o sistema dizia e o que a prateleira tinha.
 *
 * @property int $id
 * @property int $stock_count_id
 * @property int $product_id
 * @property string $qty_system
 * @property string|null $qty_counted
 */
class StockCountItem extends Model
{
    /** @use HasFactory<StockCountItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'stock_count_id',
        'product_id',
        'qty_system',
        'qty_counted',
    ];

    /**
     * @return BelongsTo<StockCount, $this>
     */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function contado(): bool
    {
        return $this->qty_counted !== null;
    }

    /**
     * Contado menos sistema. Positivo achou peça, negativo faltou.
     *
     * Null enquanto ninguém contou — e null **não** é zero: num
     * inventário de 754 peças, "ainda não contei" e "contei e não tem
     * nenhuma" levam a ações opostas.
     */
    public function divergencia(): ?string
    {
        return $this->qty_counted === null
            ? null
            : bcsub($this->qty_counted, $this->qty_system, 3);
    }

    public function divergente(): bool
    {
        $divergencia = $this->divergencia();

        return $divergencia !== null && bccomp($divergencia, '0', 3) !== 0;
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeContados(Builder $query): Builder
    {
        return $query->whereNotNull('qty_counted');
    }
}
