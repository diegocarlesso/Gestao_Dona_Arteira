<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Itens do pedido — BR-302, docs/10-Vendas §4.
 *
 * **Preço congelado no item.** `unit_price` guarda o preço do momento em
 * que a peça entrou no pedido; mudar a tabela de preços depois não altera
 * pedido nenhum. Um pedido de seis meses atrás precisa continuar
 * explicável pelo valor que se cobrou, não pelo de hoje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id');

            // Referência ao produto do Catálogo. FK como o
            // `inventory_movements` faz — o banco é um só (ADR-0002) e
            // produto não se apaga (BR-008, vira archived), então a FK
            // nunca dispara. O **nome** do item na tela vem por Service do
            // Catálogo (ADR-0020), não daqui.
            $table->foreignId('product_id');

            $table->decimal('qty', 15, 3);

            // Congelado (BR-302), DECIMAL(15,2) como todo dinheiro.
            $table->decimal('unit_price', 15, 2);

            $table->decimal('discount', 15, 2)->default(0);

            // Personalização/observação do item (o legado tinha) — ex.:
            // "sem verniz", "cor especial".
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->foreign('order_id', 'fk_order_items_order')
                ->references('id')->on('orders')->cascadeOnDelete();

            $table->foreign('product_id', 'fk_order_items_product')
                ->references('id')->on('products')->restrictOnDelete();

            // Um produto uma vez por pedido: quem quer mais soma na
            // quantidade, não cria segunda linha — a tela e o total ficam
            // legíveis.
            $table->unique(['order_id', 'product_id'], 'uq_order_items_produto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
