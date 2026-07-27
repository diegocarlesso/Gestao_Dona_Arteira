<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Um movimento entrou no ledger e o saldo já foi atualizado.
 *
 * Superfície pública do módulo (ADR-0020). Quem reage — publicar o
 * estoque no WooCommerce (BR-204), alertar mínimo, alimentar dashboard —
 * ouve isto, e o Estoque segue sem conhecer nenhum deles.
 *
 * Disparado **dentro** da transação do movimento, de propósito: um
 * listener que falhe deve derrubar o movimento junto, em vez de deixar o
 * ledger dizendo uma coisa e o site outra. Listener que possa falhar sem
 * consequência deve ser enfileirado (`ShouldQueue`), e aí o job só é
 * despachado depois do commit.
 */
class StockMovementRecorded
{
    use Dispatchable;

    public function __construct(public readonly InventoryMovement $movimento) {}
}
