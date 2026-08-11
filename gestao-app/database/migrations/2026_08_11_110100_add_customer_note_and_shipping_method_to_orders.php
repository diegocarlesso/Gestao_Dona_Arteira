<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comentário do cliente e forma de entrega — BR-707.
 *
 * `customer_note` é o texto que o cliente digitou no checkout do site —
 * nunca confundir com `orders.notes`, que é gerado pelo próprio ERP
 * (pendência de importação, aviso de total divergente etc.). Misturar os
 * dois faria uma mensagem do sistema apagar o que o cliente pediu, ou o
 * contrário.
 *
 * `shipping_method` é o texto livre da forma escolhida no site (ex.:
 * "Loggi Express (Melhor Envio)") — o **valor** do frete já existia em
 * `orders.shipping`; isso só nasceu sem o nome do serviço.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_note', 1000)->nullable()->after('notes');
            $table->string('shipping_method', 255)->nullable()->after('shipping');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['customer_note', 'shipping_method']);
        });
    }
};
