<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\PriceList;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Um preço de lista, válido a partir de uma data — BR-003.
 *
 * **Histórico, não estado.** Preço novo é linha nova; nunca `UPDATE` na
 * anterior. Um pedido de seis meses atrás precisa continuar explicável, e
 * isso exige saber quanto a peça custava naquele dia.
 *
 * @property int $id
 * @property int $product_id
 * @property PriceList $price_list
 * @property string $price
 * @property Carbon $valid_from
 */
class ProductPrice extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'price_list',
        'price',
        'valid_from',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_list' => PriceList::class,
            'valid_from' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Linha de preço não se edita nem se apaga: é registro histórico,
        // igual a uma entrada de trilha. Corrigir um preço errado é criar
        // a linha certa com `valid_from` de agora — o erro fica visível,
        // que é como deve ser.
        static::updating(static fn (): bool => false);
        static::deleting(static fn (): bool => false);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * O valor como dinheiro de verdade (ADR-0013) — nunca float.
     */
    public function money(): Money
    {
        return Money::of($this->price, 'BRL');
    }
}
