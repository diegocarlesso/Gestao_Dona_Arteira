<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contagem física de estoque — BR-205, docs/09-Estoque §5.
 *
 * É por aqui que o saldo inicial do ERP nasce: nem o `in_stock` do Woo
 * nem o do legado são confiáveis por construção — 708 de 716 produtos
 * sequer controlavam estoque no site.
 *
 * **Aprovador ≠ contador.** A segregação é a razão de a contagem ser uma
 * entidade, e não um formulário de ajuste em lote: quem conta registra o
 * que viu, e outra pessoa confere a divergência antes de o ajuste virar
 * movimento. Um ajuste que a mesma pessoa lança e aprova não é controle,
 * é digitação com etapa extra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('location_id');

            // counting / awaiting_approval / approved / cancelled
            $table->string('status', 24)->default('counting');

            $table->string('notes', 500)->nullable();

            /**
             * Quem contou e quem aprovou — as duas pessoas que a BR-205
             * exige serem diferentes.
             *
             * `counted_by` é nulável só porque o usuário pode ser removido
             * depois; a contagem nasce sempre com autor.
             */
            $table->foreignId('counted_by')->nullable();
            $table->foreignId('approved_by')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->foreign('location_id', 'fk_stock_counts_location')
                ->references('id')->on('locations')->restrictOnDelete();

            $table->foreign('counted_by', 'fk_stock_counts_counter')
                ->references('id')->on('users')->nullOnDelete();

            $table->foreign('approved_by', 'fk_stock_counts_approver')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['status', 'location_id'], 'idx_stock_counts_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_counts');
    }
};
