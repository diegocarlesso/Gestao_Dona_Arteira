<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos — BR-303, docs/10-Vendas.
 *
 * Um fluxo único para todos os canais (pasta 10 §1). Este corte cria o
 * pedido do ERP (balcão/atacado/encomenda); o do site (BR-304) entra pela
 * integração no corte da sync, usando a mesma tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            // Sequencial legível ("Pedido #42"), o que a operação usa; o
            // public_id é para a URL (convenção da pasta 04).
            $table->unsignedBigInteger('number')->unique();

            // erp / woocommerce
            $table->string('channel', 16)->default('erp');

            // Nulável: venda de balcão para "Consumidor" não exige cliente
            // cadastrado (a exigência de documento é do faturamento, não do
            // pedido — mesma lógica da BR-001 nos clientes).
            $table->foreignId('customer_id')->nullable();

            // draft / confirmed / cancelled (este corte)
            $table->string('status', 16)->default('draft');

            // Lista aplicada. Só `retail` por ora — a de atacado não tem
            // dado nem regra de elegibilidade (BR-301, adiado na pasta 10).
            $table->string('price_list', 16)->default('retail');

            // Totais em DECIMAL(15,2) (ADR-0013). Desconto e frete nascem
            // zerados neste corte (sem desconto com alçada ainda, BR-305;
            // sem frete até a expedição); as colunas já existem porque a
            // pasta 04 as prevê e o pedido vai usá-las nos cortes seguintes.
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('shipping', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            $table->string('notes', 1000)->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->foreignId('created_by')->nullable();

            $table->timestamps();

            $table->foreign('customer_id', 'fk_orders_customer')
                ->references('id')->on('customers')->nullOnDelete();

            $table->foreign('created_by', 'fk_orders_user')
                ->references('id')->on('users')->nullOnDelete();

            // Listagens da pasta 04: por status e por cliente/data.
            $table->index(['status', 'created_at'], 'idx_orders_status');
            $table->index(['customer_id', 'created_at'], 'idx_orders_customer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
