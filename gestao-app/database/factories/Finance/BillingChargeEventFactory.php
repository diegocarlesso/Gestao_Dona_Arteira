<?php

declare(strict_types=1);

namespace Database\Factories\Finance;

use App\Modules\Finance\Models\BillingCharge;
use App\Modules\Finance\Models\BillingChargeEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingChargeEvent>
 */
class BillingChargeEventFactory extends Factory
{
    protected $model = BillingChargeEvent::class;

    public function definition(): array
    {
        return [
            'charge_id' => BillingCharge::factory(),
            'type' => 'payment.updated',
            'provider_event_id' => (string) $this->faker->unique()->numberBetween(1000000, 9999999),
            'payload' => ['status' => 'approved'],
            'processed_at' => null,
            'error' => null,
        ];
    }
}
