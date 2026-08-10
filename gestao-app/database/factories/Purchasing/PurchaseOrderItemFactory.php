<?php

declare(strict_types=1);

namespace Database\Factories\Purchasing;

use App\Modules\Catalog\Models\Product;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrderItem>
 */
class PurchaseOrderItemFactory extends Factory
{
    protected $model = PurchaseOrderItem::class;

    /**
     * Sem @return próprio: herda o do Factory, que o Larastan lê como
     * "chaves = propriedades reais do model" (checkModelProperties).
     */
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            // Factory do Catálogo, não Service: a factory é da camada de
            // teste e monta o cenário; a fronteira do ADR-0020 vale para o
            // código de aplicação (`app/Modules`), não para `database/`.
            'product_id' => Product::factory(),
            'qty' => '10.000',
            'unit_cost' => '12.50',
            'line_total' => '125.00',
        ];
    }
}
