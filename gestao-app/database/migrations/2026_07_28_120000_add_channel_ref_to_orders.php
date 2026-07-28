<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referência do pedido no canal de origem — BR-304, docs/16 (de-para).
 *
 * O pedido do site tem numeração própria no Woo ("Pedido #727"), que a
 * operação reconhece e o cliente cita ao ligar. O `id` do Woo já mora no
 * `integration_mappings` para casar e garantir idempotência (BR-704); mas
 * o id de banco não é o número que aparece na loja — plugins de numeração
 * fazem os dois divergirem. Guardar o número visível aqui evita ter de
 * adivinhar depois qual pedido do site é este.
 *
 * Só de leitura e nulável: pedido do ERP não tem referência de canal.
 * Expand-only (pasta 04) — coluna nova, nada reescrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel_order_ref', 64)->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('channel_order_ref');
        });
    }
};
