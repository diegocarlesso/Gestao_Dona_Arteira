<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico da máquina de estados do pedido de compra — espelha
 * `order_status_history` (BR-303) pelo mesmo motivo.
 *
 * O PC confirmado vira dívida (BR-402), e "quem mandou este pedido ao
 * fornecedor e quem o confirmou" é exatamente a pergunta que se faz quando
 * chega uma cobrança que ninguém reconhece. Append-only, como toda trilha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_status_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_order_id');

            // Nulo na criação (não veio de estado nenhum).
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);

            $table->string('reason', 500)->nullable();

            // Nulo = sistema (importação, comando).
            $table->foreignId('created_by')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('purchase_order_id', 'fk_purchase_order_status_history_pc')
                ->references('id')->on('purchase_orders')->cascadeOnDelete();

            $table->foreign('created_by', 'fk_purchase_order_status_history_user')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['purchase_order_id', 'created_at'], 'idx_purchase_order_status_history_pc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_status_history');
    }
};
