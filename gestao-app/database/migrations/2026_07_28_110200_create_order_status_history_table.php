<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico da máquina de estados do pedido — BR-303.
 *
 * Toda transição grava uma linha: de onde, para onde, quem e por quê. É a
 * trilha que responde "por que este pedido foi cancelado, e quem
 * cancelou?" — a mesma pergunta que o extrato responde para o estoque.
 * Append-only, como toda trilha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id');

            // Nulo na criação (não veio de estado nenhum).
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);

            $table->string('reason', 500)->nullable();

            // Nulo = sistema (ex.: pedido do Woo entra confirmado sem um
            // usuário do ERP por trás).
            $table->foreignId('created_by')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('order_id', 'fk_order_status_history_order')
                ->references('id')->on('orders')->cascadeOnDelete();

            $table->foreign('created_by', 'fk_order_status_history_user')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['order_id', 'created_at'], 'idx_order_status_history_pedido');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
