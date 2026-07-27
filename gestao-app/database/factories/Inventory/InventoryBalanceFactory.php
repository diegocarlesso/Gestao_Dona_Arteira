<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryBalance>
 */
class InventoryBalanceFactory extends Factory
{
    protected $model = InventoryBalance::class;

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'qty_on_hand' => '0.000',
            'qty_reserved' => '0.000',
            'avg_cost' => '0.00',
        ];
    }
}
