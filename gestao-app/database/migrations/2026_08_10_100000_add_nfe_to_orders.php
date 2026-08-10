<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Situação fiscal no pedido — BR-309, ADR-0025 §3.
 *
 * Colunas do **próprio** módulo Vendas, não uma referência à `invoices`.
 * Vendas precisa responder "posso expedir?" sem conhecer
 * `Fiscal\Models\Invoice` (ADR-0020): o Fiscal anuncia `InvoiceAuthorized`
 * / `InvoiceRejected`, um listener de Vendas escreve aqui, e o gate da
 * expedição lê daqui. Duas tabelas guardando o mesmo fato é o preço da
 * fronteira — e o preço certo, porque o fato que Vendas guarda é "esta
 * venda está liberada", não "existe uma nota número tal".
 *
 * `nfe_status` nulo = **não se aplica ou ainda não começou**. Isso é o que
 * mantém os pedidos em fluxo no momento do deploy destravados: o gate só
 * exige nota do canal que a exige (site, neste corte), e pedido de balcão
 * segue expedindo com a coluna nula para sempre.
 *
 * Expand-only (pasta 04): colunas novas, nuláveis, nada reescrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // pending / authorized / rejected / cancelled — o mesmo
            // vocabulário do `InvoiceStatus` do Fiscal, copiado como
            // string em vez de importado: um enum compartilhado entre os
            // dois módulos seria a fronteira furada por dentro.
            $table->string('nfe_status', 16)->nullable()->after('carrier');
            $table->timestamp('invoice_authorized_at')->nullable()->after('nfe_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['nfe_status', 'invoice_authorized_at']);
        });
    }
};
