<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagamento do pedido — BR-501, pré-requisito do núcleo financeiro (Gate 04).
 *
 * `payment_method` é texto livre, mesmo padrão de `shipping_method`
 * (BR-707): o vocabulário real (balcão, Woo, Mercado Pago direto) ainda
 * não está consolidado o bastante para virar enum.
 *
 * `paid_at` nulo é o estado normal de um título recém-nascido — só deixa
 * de ser nulo quando o pagamento é confirmado (à vista no balcão, ou
 * `processing` vindo do Woo). É o campo que o listener do Financeiro lê
 * para decidir se o título a receber nasce aberto ou já baixado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method', 64)->nullable()->after('shipping_method');
            $table->timestamp('paid_at')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'paid_at']);
        });
    }
};
