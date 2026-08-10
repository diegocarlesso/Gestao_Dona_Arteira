<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos de compra — BR-402, docs/11-Compras (Fase 1).
 *
 * **Nesta fase o PC é documento financeiro, não movimento de estoque.**
 * Confirmar o PC gera a conta a pagar direto (nota de fase da pasta 11);
 * o recebimento físico com conferência, a quarentena de secagem (BR-404) e
 * o custo médio são a Fase 2, junto do Estoque. Por isso não há aqui
 * `received_at` nem vínculo com movimento: coluna que ninguém escreve
 * mente sobre o que o sistema faz.
 *
 * `expected_delivery_at` já existe porque é o que a operação pergunta
 * ("quando chega?") e é preenchido pelo lead time do fornecedor — não
 * depende do recebimento existir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            // Sequencial legível ("PC #12"), o que a operação fala ao
            // telefone; o public_id é para a URL (convenção da pasta 04).
            $table->unsignedInteger('number')->unique();

            $table->foreignId('supplier_id');

            // draft / sent / confirmed / cancelled
            $table->string('status', 16)->default('draft');

            // ADR-0013: dinheiro é DECIMAL, nunca float. `freight` é
            // destacado e **soma no total** — se ele entra no custo médio
            // da peça é pergunta aberta com o contador (pasta 11 §7), e
            // essa decisão é da Fase 2; guardá-lo separado agora é o que
            // deixa as duas respostas possíveis depois.
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('freight', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            // Data do pedido ao fornecedor. Nula enquanto rascunho; é a
            // base do vencimento do título (emissão + prazo do fornecedor).
            $table->date('issued_at')->nullable();
            $table->date('expected_delivery_at')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable();

            $table->timestamps();

            // `restrict`: fornecedor com pedido de compra não some do
            // cadastro por acidente — o histórico do que se comprou dele
            // deixaria de ter dono.
            $table->foreign('supplier_id', 'fk_purchase_orders_supplier')
                ->references('id')->on('suppliers')->restrictOnDelete();

            $table->foreign('created_by', 'fk_purchase_orders_user')
                ->references('id')->on('users')->nullOnDelete();

            // As duas listagens da tela: "o que está aberto" e "o que
            // comprei deste fornecedor".
            $table->index(['status', 'created_at'], 'idx_purchase_orders_status');
            $table->index(['supplier_id', 'created_at'], 'idx_purchase_orders_supplier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
