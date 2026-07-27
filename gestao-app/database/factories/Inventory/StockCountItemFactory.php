<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockCountItem>
 */
class StockCountItemFactory extends Factory
{
    protected $model = StockCountItem::class;

    public function definition(): array
    {
        return [
            'stock_count_id' => StockCount::factory(),
            'qty_system' => '0.000',
            'qty_counted' => null,
        ];
    }
}
