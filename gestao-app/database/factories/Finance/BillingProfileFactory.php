<?php

declare(strict_types=1);

namespace Database\Factories\Finance;

use App\Modules\Finance\Models\BillingProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingProfile>
 */
class BillingProfileFactory extends Factory
{
    protected $model = BillingProfile::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'fine_pct' => null,
            'interest_pct_month' => null,
            'discount_pct' => null,
            'discount_days' => null,
            'days_to_due' => 15,
            'instructions' => null,
            'valid_from' => null,
            'valid_to' => null,
        ];
    }
}
