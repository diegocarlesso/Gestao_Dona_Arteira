<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uma cobrança (PIX com vencimento ou boleto) registrada no provedor —
 * BR-505/506/507/509/510, docs/12-Financeiro/01-cobranca-e-boletos.md §4.
 *
 * FK real para `finance_receivables` (não `origin_type`/`origin_id`): as
 * duas tabelas moram no mesmo módulo, então é Eloquent comum, não uma
 * fronteira sendo forçada (mesmo raciocínio do `settleable_id` em
 * `HasSettlements`). BR-505: cobrança não existe sem título — por isso a
 * FK é obrigatória, nunca nullable.
 *
 * **Dados do pagador são snapshot nesta tabela**, não uma referência ao
 * cadastro de cliente: `Receivable` guarda só `customer_name` (ADR-0020 —
 * o Financeiro não conhece o esquema de Vendas/clientes), e o provedor
 * exige CPF/CNPJ, e-mail e, para boleto, endereço completo do pagador.
 * Sem um vínculo Receivable→Customer, o operador confirma esses dados no
 * momento de emitir a cobrança; ficam gravados aqui porque são o que foi
 * de fato enviado ao Mercado Pago, não o cadastro atual do cliente.
 *
 * `provider_charge_id` nasce nulo: a emissão é assíncrona (fila, docs/15
 * §3) — a linha nasce em `pending` no mesmo request que o operador clica
 * "emitir", o job preenche o restante quando o provedor responde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_charges', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('receivable_id')
                ->constrained('finance_receivables')
                ->restrictOnDelete();

            $table->foreignId('profile_id')->nullable()
                ->constrained('billing_profiles')
                ->nullOnDelete();

            $table->string('provider', 32)->default('mercado_pago');
            $table->string('provider_charge_id', 64)->nullable()->unique();

            $table->string('type', 16);
            $table->decimal('amount', 15, 2);
            $table->date('due_date')->nullable();

            // pending (aguardando registro no provedor) · registered
            // (provedor confirmou, tem QR/linha digitável) · paid ·
            // partially_paid · cancelled · expired · failed.
            $table->string('status', 20)->default('pending');

            $table->string('digitable_line', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->text('pix_payload')->nullable();
            $table->string('pdf_url', 255)->nullable();

            $table->decimal('fee_amount', 15, 2)->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('failure_reason', 500)->nullable();

            $table->string('payer_name', 190)->nullable();
            $table->string('payer_email', 190)->nullable();
            $table->string('payer_document_type', 8)->nullable();
            $table->string('payer_document_number', 20)->nullable();
            $table->string('payer_address_zip', 10)->nullable();
            $table->string('payer_address_street', 190)->nullable();
            $table->string('payer_address_number', 20)->nullable();
            $table->string('payer_address_neighborhood', 120)->nullable();
            $table->string('payer_address_city', 120)->nullable();
            $table->string('payer_address_state', 2)->nullable();

            $table->timestamps();

            $table->index(['receivable_id', 'status'], 'idx_billing_charges_titulo');
            $table->index('status', 'idx_billing_charges_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_charges');
    }
};
