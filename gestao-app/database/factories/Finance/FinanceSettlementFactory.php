<?php

declare(strict_types=1);

namespace Database\Factories\Finance;

use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\Payable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Baixa **crua**, sem passar pelo `RegisterSettlementService` — mesmo
 * motivo de `PayableFactory`: teste de regra usa o Service, que valida
 * saldo e resolve o título.
 *
 * @extends Factory<FinanceSettlement>
 */
class FinanceSettlementFactory extends Factory
{
    protected $model = FinanceSettlement::class;

    public function definition(): array
    {
        return [
            'settleable_type' => 'payable',
            'settleable_id' => Payable::factory(),
            'account_id' => FinanceAccount::factory(),
            'amount' => '100.00',
            'settled_at' => now()->toDateString(),
            'notes' => null,
            'reversal_of_id' => null,
        ];
    }
}
