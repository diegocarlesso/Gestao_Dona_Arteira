<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'qty' => '1.000',
            'unit_price' => '89.90',
            'discount' => '0.00',
        ];
    }
}
