<?php

declare(strict_types=1);

namespace Database\Factories\Finance;

use App\Modules\Finance\Enums\FinanceCategoryType;
use App\Modules\Finance\Models\FinanceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceCategory>
 */
class FinanceCategoryFactory extends Factory
{
    protected $model = FinanceCategory::class;

    /**
     * O nome é único porque (tipo, nome) é único no banco — dois `create()`
     * seguidos numa mesma suíte colidiriam com um faker sem `unique()`.
     *
     * Sem @return próprio: herda o do Factory, que o Larastan lê como
     * "chaves = propriedades reais do model" (checkModelProperties).
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'type' => FinanceCategoryType::Payable,
            'parent_id' => null,
        ];
    }

    public function aReceber(): static
    {
        return $this->state(fn (): array => ['type' => FinanceCategoryType::Receivable]);
    }

    public function filhaDe(FinanceCategory $pai): static
    {
        return $this->state(fn (): array => [
            'parent_id' => $pai->id,
            'type' => $pai->type,
        ]);
    }
}
