<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Itens do pedido de compra — docs/11-Compras §4.
 *
 * `product_id` é FK de banco para `products` e nada além disso: o Compras
 * não tem relação Eloquent com `Catalog\Models\Product` (ADR-0020) — o
 * nome da peça na tela vem por `ProductLookupService`, igual ao item do
 * pedido de venda e ao movimento de estoque.
 *
 * `line_total` é gravado, não calculado na leitura, pelo mesmo motivo do
 * `subtotal` do PC: é o valor negociado daquela linha, e a soma tem de
 * bater com o título a pagar mesmo que a conta mude de forma depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_order_id');
            $table->foreignId('product_id');

            // Quantidade em DECIMAL(15,3) — a tinta é comprada em litro
            // fracionado (regra 6 do CLAUDE.md).
            $table->decimal('qty', 15, 3);

            // Custo negociado da unidade, não o preço de tabela do
            // fornecedor: o que se pagou é o que entra na conta.
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('line_total', 15, 2);

            $table->timestamps();

            $table->foreign('purchase_order_id', 'fk_purchase_order_items_pc')
                ->references('id')->on('purchase_orders')->cascadeOnDelete();

            // `restrict`: produto com histórico de compra não se apaga —
            // o catálogo arquiva (ADR-0022), não deleta.
            $table->foreign('product_id', 'fk_purchase_order_items_product')
                ->references('id')->on('products')->restrictOnDelete();

            // Uma linha por produto no mesmo PC: duas linhas da mesma peça
            // seriam a mesma compra contada duas vezes no custo.
            $table->unique(['purchase_order_id', 'product_id'], 'uk_purchase_order_items_produto');

            // "De quem e por quanto eu já comprei esta peça?" — a consulta
            // que sustenta a decisão de compra (pasta 11 §6).
            $table->index('product_id', 'idx_purchase_order_items_produto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
