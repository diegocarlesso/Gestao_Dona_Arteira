<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As linhas de uma contagem — BR-205.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_count_id');
            $table->foreignId('product_id');

            /**
             * O saldo do sistema **no momento em que a contagem abriu**.
             *
             * Congelado de propósito (pasta 09 §5): a divergência é entre
             * o que a prateleira tinha e o que o sistema dizia *naquele
             * instante*. Comparar com o saldo de agora faria a venda
             * ocorrida durante a contagem virar divergência de contagem, e
             * o ajuste apagaria uma expedição real.
             */
            $table->decimal('qty_system', 15, 3);

            // Nulo até alguém contar. É o que distingue "contei zero" de
            // "ainda não contei" — e num inventário de 754 peças essa
            // diferença é o que diz onde parou o trabalho.
            $table->decimal('qty_counted', 15, 3)->nullable();

            $table->timestamps();

            $table->foreign('stock_count_id', 'fk_stock_count_items_count')
                ->references('id')->on('stock_counts')->cascadeOnDelete();

            $table->foreign('product_id', 'fk_stock_count_items_product')
                ->references('id')->on('products')->restrictOnDelete();

            // Um produto aparece uma vez por contagem — sem isto, abrir a
            // mesma contagem duas vezes por engano duplicaria as linhas e
            // o ajuste sairia dobrado.
            $table->unique(['stock_count_id', 'product_id'], 'uq_stock_count_items_produto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
    }
};
