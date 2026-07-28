<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'number' => fake()->unique()->numberBetween(1, 999999),
            'channel' => OrderChannel::Erp,
            'status' => OrderStatus::Draft,
            'price_list' => 'retail',
            'subtotal' => '0.00',
            'discount' => '0.00',
            'shipping' => '0.00',
            'total' => '0.00',
        ];
    }
}
