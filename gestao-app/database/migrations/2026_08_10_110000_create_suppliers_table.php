<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fornecedores — docs/11-Compras §4 (Fase 1).
 *
 * De quem se compra a peça crua, a tinta, o verniz e a embalagem. O
 * cadastro guarda dois prazos que não são enfeite: `payment_terms_days`
 * vira o vencimento do título a pagar (BR-402) e `lead_time_days` é o que
 * a sugestão de reposição vai somar ao mínimo quando o Estoque entrar
 * (Fase 2) — nascem aqui porque são dado de cadastro, não de fase.
 *
 * `softDeletes` pelo mesmo motivo do cliente: fornecedor inativo tem
 * pedido de compra apontando para ele, e apagar reescreveria o histórico
 * do que já se comprou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->string('legal_name');

            // Como a operação chama ("Gesso do Zé"), que raramente é o que
            // está no contrato social.
            $table->string('trade_name')->nullable();

            // Só dígitos na coluna (o model normaliza), como em `customers.doc`
            // — senão "12.345.678/0001-95" e "12345678000195" seriam dois
            // fornecedores para o banco e um só para a Receita.
            $table->string('document', 20)->unique();

            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();

            // Prazo médio de pagamento em dias (28, 30/60...). Nulo = à
            // vista/a combinar: o título nasce sem vencimento em vez de com
            // um vencimento inventado.
            $table->unsignedSmallInteger('payment_terms_days')->nullable();

            // Prazo médio de entrega em dias.
            $table->unsignedSmallInteger('lead_time_days')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('legal_name', 'idx_suppliers_nome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
