<?php

declare(strict_types=1);

namespace Database\Factories\Finance;

use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Models\Receivable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Título **cru**, sem passar pelo serviço — mesmo motivo de `PayableFactory`.
 *
 * @extends Factory<Receivable>
 */
class ReceivableFactory extends Factory
{
    protected $model = Receivable::class;

    public function definition(): array
    {
        return [
            'category_id' => FinanceCategory::factory()->aReceber(),
            'origin_type' => 'order',
            'origin_id' => $this->faker->numberBetween(1, 9999),
            'customer_name' => $this->faker->name(),
            'amount' => '250.00',
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Receivable::STATUS_OPEN,
        ];
    }

    public function vencido(): static
    {
        return $this->state(fn (): array => ['due_date' => now()->subDays(10)->toDateString()]);
    }

    public function semVencimento(): static
    {
        return $this->state(fn (): array => ['due_date' => null]);
    }
}
