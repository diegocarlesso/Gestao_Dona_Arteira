<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Events;

use App\Modules\Fiscal\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A SEFAZ autorizou a nota — ADR-0025 §3.
 *
 * Superfície pública do módulo (ADR-0020). Quem reage: Vendas, para liberar
 * a expedição (BR-309); depois, o envio do XML/DANFE ao cliente e ao
 * contador (docs/14 §5). O Fiscal não precisa conhecer nenhum deles.
 *
 * Carrega o `orderId` **separado** da `Invoice`, e não por conveniência:
 * quem ouve do outro lado da fronteira (o listener de Vendas) não pode
 * tocar `Fiscal\Models\Invoice` — precisa do id do pedido em primitivo para
 * fazer o seu trabalho sem importar o model.
 */
class InvoiceAuthorized
{
    use Dispatchable;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly int $orderId,
    ) {}
}
