<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Database\Factories\Inventory\InventoryBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo materializado de um produto num local — ADR-0008.
 *
 * **Não é a verdade, é a soma dela.** A verdade está em
 * `inventory_movements`; esta linha existe para não somar milhões de
 * movimentos a cada consulta de disponibilidade. Só o
 * `RecordMovementService` escreve aqui, na mesma transação do movimento e
 * com lock — qualquer outro caminho de escrita quebraria a equivalência
 * que a reconciliação confere.
 *
 * @property int $id
 * @property int $product_id
 * @property int $location_id
 * @property string $qty_on_hand
 * @property string $qty_reserved
 * @property string $avg_cost
 */
class InventoryBalance extends Model
{
    /** @use HasFactory<InventoryBalanceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'location_id',
        'qty_on_hand',
        'qty_reserved',
        'avg_cost',
    ];

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * O que pode ser vendido: físico menos reservado (BR-203).
     *
     * O buffer da BR-204 **não** entra aqui — ele é por produto e só se
     * aplica ao número publicado no site. Misturar os dois faria a
     * operação do balcão enxergar menos peça do que existe na prateleira.
     */
    public function disponivel(): string
    {
        return bcsub($this->qty_on_hand, $this->qty_reserved, 3);
    }
}
