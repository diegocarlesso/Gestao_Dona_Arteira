<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contas a receber — BR-501/BR-503, docs/12-Financeiro §3.
 *
 * Espelho estrutural de `finance_payables`: mesma ausência de FK para o
 * cliente/pedido (ADR-0020 — o Financeiro não conhece o esquema de
 * Vendas), origem em `origin_type`/`origin_id`, nome do cliente como
 * snapshot (renomear o cadastro não reescreve o que já se deve).
 *
 * Diferente da fatia mínima de `finance_payables` (que nasceu só com
 * `open`), `status` já sai com os três valores que a baixa (BR-502)
 * precisa: `open`, `partially_settled`, `settled` — porque a baixa nasce
 * junto nesta mesma migração, não depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_receivables', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('category_id');

            $table->string('origin_type', 64)->nullable();
            $table->unsignedBigInteger('origin_id')->nullable();

            $table->string('customer_name');

            $table->decimal('amount', 15, 2);

            $table->date('due_date')->nullable();

            $table->string('status', 20)->default('open');

            $table->timestamps();

            $table->foreign('category_id', 'fk_finance_receivables_category')
                ->references('id')->on('finance_categories')->restrictOnDelete();

            $table->index(['origin_type', 'origin_id'], 'idx_finance_receivables_origem');
            $table->index(['status', 'due_date'], 'idx_finance_receivables_vencimento');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_receivables');
    }
};
