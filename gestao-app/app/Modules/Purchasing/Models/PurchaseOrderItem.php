<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use Database\Factories\Purchasing\PurchaseOrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um item do pedido de compra, com o custo negociado — docs/11-Compras §4.
 *
 * Guarda `product_id` e não o nome: o nome vem por Service do Catálogo
 * (ADR-0020), como no item do pedido de venda e no movimento de estoque.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $product_id
 * @property string $qty
 * @property string $unit_cost
 * @property string $line_total
 */
class PurchaseOrderItem extends Model
{
    /** @use HasFactory<PurchaseOrderItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'qty',
        'unit_cost',
        'line_total',
    ];

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Quantidade × custo unitário, em string via bc — dinheiro e
     * quantidade nunca passam por float (ADR-0013).
     *
     * É a conta que **produz** `line_total` ao salvar; existe como método
     * para que quem recalcule use a mesma fórmula em vez de repeti-la.
     */
    public function calcularTotal(): string
    {
        return bcmul($this->qty, $this->unit_cost, 2);
    }
}
