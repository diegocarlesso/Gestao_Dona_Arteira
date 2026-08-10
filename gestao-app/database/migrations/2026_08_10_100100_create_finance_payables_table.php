<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contas a pagar — BR-501/BR-503, docs/12-Financeiro §3.
 *
 * **Fatia mínima adiantada para o P0**: o título nasce da confirmação do
 * Pedido de Compra (BR-402, Fase 1 da pasta 11) e fica em `open`. A baixa
 * (BR-502, `finance_settlements`), as contas financeiras e o fluxo de caixa
 * são o Gate 04 — nada aqui presume que eles não virão, mas nada aqui os
 * antecipa.
 *
 * **Sem FK para o fornecedor nem para o pedido de compra.** O ADR-0020
 * proíbe o Financeiro conhecer o esquema do Compras. A origem vai em
 * `origin_type`/`origin_id` (mesmo par de `stock_reservations`) e o nome do
 * fornecedor é **snapshot**: renomear o cadastro do fornecedor não pode
 * reescrever o histórico do que já se deve — é o mesmo princípio do preço
 * congelado no item do pedido (BR-302).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_payables', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('category_id');

            // Origem do título (o pedido de compra), sem FK cross-módulo.
            $table->string('origin_type', 64)->nullable();
            $table->unsignedBigInteger('origin_id')->nullable();

            $table->string('supplier_name');

            // ADR-0013: dinheiro é DECIMAL, nunca float.
            $table->decimal('amount', 15, 2);

            // Nulo = "a combinar" — a compra existe, o vencimento ainda não
            // foi acertado. Título sem vencimento não entra no caixa
            // projetado, mas continua devido e visível no aberto.
            $table->date('due_date')->nullable();

            // open — por ora o único estado. Gate 04 acrescenta os da baixa.
            $table->string('status', 16)->default('open');

            $table->timestamps();

            $table->foreign('category_id', 'fk_finance_payables_category')
                ->references('id')->on('finance_categories')->restrictOnDelete();

            // "Que títulos este pedido de compra gerou?" — a pergunta que o
            // Compras faz para mostrar o título na tela do PC, e a que
            // garante a idempotência da confirmação (não duplicar título).
            $table->index(['origin_type', 'origin_id'], 'idx_finance_payables_origem');

            // "O que vence, e quando" — o caixa projetado e o aging (1–15,
            // 16–30, 30+) leem por aqui.
            $table->index(['status', 'due_date'], 'idx_finance_payables_vencimento');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payables');
    }
};
