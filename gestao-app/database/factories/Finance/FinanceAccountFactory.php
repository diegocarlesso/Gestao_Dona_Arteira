<?php

declare(strict_types=1);

namespace Database\Factories\Finance;

use App\Modules\Finance\Enums\FinanceAccountType;
use App\Modules\Finance\Models\FinanceAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceAccount>
 */
class FinanceAccountFactory extends Factory
{
    protected $model = FinanceAccount::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'type' => FinanceAccountType::Cash,
            'notes' => null,
        ];
    }
}
