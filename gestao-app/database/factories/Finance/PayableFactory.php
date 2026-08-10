<?php

declare(strict_types=1);

namespace Database\Factories\Finance;

use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Models\Payable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Título **cru**, sem passar pelo serviço — para testar o model e os
 * escopos. Teste de regra usa o `RegisterPayableService`, que é quem
 * resolve a categoria e valida o valor; um título que o contorne provaria
 * algo que a aplicação não faz.
 *
 * @extends Factory<Payable>
 */
class PayableFactory extends Factory
{
    protected $model = Payable::class;

    /**
     * Sem @return próprio: herda o do Factory, que o Larastan lê como
     * "chaves = propriedades reais do model" (checkModelProperties).
     */
    public function definition(): array
    {
        return [
            'category_id' => FinanceCategory::factory(),
            'origin_type' => 'purchase_order',
            'origin_id' => $this->faker->numberBetween(1, 9999),
            'supplier_name' => $this->faker->company(),
            'amount' => '250.00',
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Payable::STATUS_OPEN,
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
