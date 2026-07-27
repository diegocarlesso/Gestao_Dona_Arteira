<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Movimento **cru**, gravado sem passar pelo serviço.
 *
 * Existe para testar o model — imutabilidade, escopos, sinal — e para
 * montar cenário de reconciliação, onde o ponto é justamente o saldo
 * discordar do ledger. Teste de regra de negócio usa o
 * `RecordMovementService`: é ele que valida BR-201 e atualiza saldo, e um
 * teste que o contorne provaria algo que a aplicação não faz.
 *
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    protected $model = InventoryMovement::class;

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'type' => MovementType::AdjustmentIn,
            'qty' => '1.000',
            'occurred_at' => now(),
        ];
    }
}
