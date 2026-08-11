<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baixa de título — BR-502/BR-504, docs/12-Financeiro §3.
 *
 * Uma tabela só para os dois lados (pagável e recebível): o diagrama da
 * doc já mostra os dois convergindo num `finance_settlements` só, e o
 * comportamento (total/parcial, conta, data, estorno por contrapartida) é
 * idêntico dos dois lados — duplicar a tabela duplicaria a regra.
 *
 * `settleable_type`/`settleable_id` é `morphTo` de verdade (não o par
 * `origin_type`/`origin_id` sem FK usado nos títulos): aqui é
 * intra-módulo — `Payable`/`Receivable` são do próprio Financeiro, então
 * não há fronteira do ADR-0020 a proteger.
 *
 * **Estorno nunca é `DELETE`** (BR-504): uma baixa de estorno é uma linha
 * nova, com `amount` negativo e `reversal_of_id` apontando para a baixa
 * que está desfazendo. O título volta a ficar (parcialmente) aberto
 * porque o saldo é recalculado a partir da soma de todas as baixas, não
 * de um contador que alguém decrementa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_settlements', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->string('settleable_type', 32);
            $table->unsignedBigInteger('settleable_id');

            $table->foreignId('account_id');

            // Negativo numa linha de estorno (BR-504); positivo numa baixa normal.
            $table->decimal('amount', 15, 2);

            $table->date('settled_at');

            $table->text('notes')->nullable();

            $table->foreignId('reversal_of_id')->nullable();

            $table->timestamps();

            $table->foreign('account_id', 'fk_finance_settlements_account')
                ->references('id')->on('finance_accounts')->restrictOnDelete();

            $table->foreign('reversal_of_id', 'fk_finance_settlements_reversal')
                ->references('id')->on('finance_settlements')->restrictOnDelete();

            $table->index(['settleable_type', 'settleable_id'], 'idx_finance_settlements_titulo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_settlements');
    }
};
