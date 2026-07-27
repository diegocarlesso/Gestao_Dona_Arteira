<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAddress>
 */
class CustomerAddressFactory extends Factory
{
    protected $model = CustomerAddress::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'label' => 'Casa',
            'zip' => fake()->numerify('########'),
            'street' => fake()->streetName(),
            'number' => (string) fake()->buildingNumber(),
            'district' => fake()->citySuffix(),
            'city' => fake()->city(),
            // RS concentra 61% dos pedidos do legado.
            'state' => 'RS',
            'is_default_shipping' => true,
            'is_default_billing' => true,
        ];
    }
}
