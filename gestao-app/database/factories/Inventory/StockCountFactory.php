<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Inventory\Enums\StockCountStatus;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockCount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockCount>
 */
class StockCountFactory extends Factory
{
    protected $model = StockCount::class;

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'status' => StockCountStatus::Counting,
        ];
    }

    public function aguardandoAprovacao(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StockCountStatus::AwaitingApproval,
            'closed_at' => now(),
        ]);
    }
}
