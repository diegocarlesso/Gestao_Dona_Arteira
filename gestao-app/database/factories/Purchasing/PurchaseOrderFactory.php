<?php

declare(strict_types=1);

namespace Database\Factories\Purchasing;

use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PC **cru**, sem passar pelos Services — para testar model, escopos e
 * telas. Teste de regra usa `SavePurchaseOrderService` e os de transição,
 * que são quem calcula o total e grava a trilha; um PC que os contorne
 * provaria algo que a aplicação não faz.
 *
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    /**
     * Sem @return próprio: herda o do Factory, que o Larastan lê como
     * "chaves = propriedades reais do model" (checkModelProperties).
     */
    public function definition(): array
    {
        return [
            'number' => $this->faker->unique()->numberBetween(1, 999999),
            'supplier_id' => Supplier::factory(),
            'status' => PurchaseOrderStatus::Draft,
            'subtotal' => '0.00',
            'freight' => '0.00',
            'total' => '0.00',
        ];
    }

    public function enviado(): static
    {
        return $this->state(fn (): array => [
            'status' => PurchaseOrderStatus::Sent,
            'issued_at' => now()->toDateString(),
        ]);
    }

    public function confirmado(): static
    {
        return $this->state(fn (): array => [
            'status' => PurchaseOrderStatus::Confirmed,
            'issued_at' => now()->toDateString(),
        ]);
    }

    public function cancelado(): static
    {
        return $this->state(fn (): array => ['status' => PurchaseOrderStatus::Cancelled]);
    }
}
