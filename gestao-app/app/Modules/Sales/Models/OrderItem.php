<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use Database\Factories\Sales\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um item do pedido, com o preço congelado — BR-302.
 *
 * Guarda `product_id`, não o nome: o nome do produto na tela vem por
 * Service do Catálogo (ADR-0020), como no estoque. O que se congela é o
 * **preço**, porque preço muda e o que se cobrou não pode mudar com ele.
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property string $qty
 * @property string $unit_price
 * @property string $discount
 * @property string|null $note
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'qty',
        'unit_price',
        'discount',
        'note',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Total da linha: quantidade × preço − desconto. Em string, via bc —
     * dinheiro e quantidade nunca passam por float (ADR-0013).
     */
    public function lineTotal(): string
    {
        $bruto = bcmul($this->qty, $this->unit_price, 2);

        return bcsub($bruto, $this->discount, 2);
    }
}
