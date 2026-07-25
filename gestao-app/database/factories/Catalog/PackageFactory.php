<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        return [
            'name' => 'Caixa '.fake()->unique()->numberBetween(10, 60).' cm',
            'height_cm' => fake()->randomFloat(2, 10, 50),
            'width_cm' => fake()->randomFloat(2, 10, 50),
            'depth_cm' => fake()->randomFloat(2, 10, 50),
            'weight_g' => fake()->randomFloat(3, 20, 500),
            'active' => true,
        ];
    }
}
