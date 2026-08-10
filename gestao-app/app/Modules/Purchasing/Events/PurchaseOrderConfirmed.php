<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Events;

use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pedido de compra confirmado, conta a pagar gerada — BR-402.
 *
 * Superfície pública do módulo (ADR-0020), paralelo a `OrderConfirmed`.
 * Quem reagir — o recebimento e o custo médio da Fase 2, o caixa projetado
 * do Gate 04, um aviso ao fornecedor — ouve isto; o Compras não precisa
 * conhecer nenhum deles.
 */
class PurchaseOrderConfirmed
{
    use Dispatchable;

    public function __construct(public readonly PurchaseOrder $pedido) {}
}
