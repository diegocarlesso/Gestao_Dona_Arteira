<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'type' => LocationType::Warehouse,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function padrao(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Ateliê',
            'type' => LocationType::Atelier,
            'is_default' => true,
        ]);
    }

    public function inativo(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
