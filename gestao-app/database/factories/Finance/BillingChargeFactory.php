<?php

declare(strict_types=1);

namespace Database\Factories\Finance;

use App\Modules\Finance\Enums\StatusCobranca;
use App\Modules\Finance\Enums\TipoCobranca;
use App\Modules\Finance\Models\BillingCharge;
use App\Modules\Finance\Models\Receivable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Cobrança **crua**, sem passar pelo `EmitirCobrancaService` — mesmo
 * motivo de `ReceivableFactory`/`PayableFactory`.
 *
 * @extends Factory<BillingCharge>
 */
class BillingChargeFactory extends Factory
{
    protected $model = BillingCharge::class;

    public function definition(): array
    {
        return [
            'receivable_id' => Receivable::factory(),
            'provider' => 'mercado_pago',
            'provider_charge_id' => null,
            'type' => TipoCobranca::Pix,
            'amount' => '250.00',
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => StatusCobranca::Pendente,
            'payer_name' => $this->faker->name(),
            'payer_email' => $this->faker->safeEmail(),
            'payer_document_type' => 'CPF',
            'payer_document_number' => $this->faker->numerify('###########'),
        ];
    }

    public function registrada(): static
    {
        return $this->state(fn (): array => [
            'provider_charge_id' => (string) $this->faker->unique()->numberBetween(1000000, 9999999),
            'status' => StatusCobranca::Registrada,
            'pix_payload' => '00020126...copia-e-cola-fake...6304ABCD',
        ]);
    }
}
