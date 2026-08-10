<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Events;

use App\Modules\Fiscal\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A SEFAZ rejeitou a nota — ADR-0025 §3.
 *
 * Gêmeo do `InvoiceAuthorized`, e igualmente público: a rejeição é
 * informação que **outros** módulos precisam (Vendas marca o pedido como
 * não liberado; o painel mostra a fila do que corrigir). Guardá-la só na
 * coluna `rejection_reason` faria cada interessado ir ler a tabela do
 * Fiscal — que é o acoplamento que o ADR-0020 evita.
 *
 * A nota rejeitada **mantém o número**: corrige-se o que a SEFAZ apontou e
 * reemite-se com o mesmo (BR-602, docs/14 §3). Rejeição não é o fim da
 * nota, é uma volta no ciclo.
 *
 * O motivo vai em `$motivo` além de estar na `Invoice`, pelo mesmo princípio
 * do `orderId` no evento irmão: quem ouve de fora não abre o model.
 */
class InvoiceRejected
{
    use Dispatchable;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly int $orderId,
        public readonly string $motivo,
    ) {}
}
