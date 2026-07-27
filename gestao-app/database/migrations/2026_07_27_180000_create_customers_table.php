<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes — BR-001, docs/10-Vendas, pasta 25 §3 (LGPD).
 *
 * **O documento é nulável, e isso é decisão, não descuido.** A BR-001
 * exige CPF/CNPJ para faturar; venda de balcão sem nota, não. Exigir o
 * documento no cadastro empurraria a operação a inventar números para
 * fechar a venda — e número inventado passa no dígito verificador de
 * ninguém, ou pior, passa no de outra pessoa. Sem documento, o cliente
 * existe e a emissão de NF-e é que fica bloqueada, no momento em que a
 * falta importa.
 *
 * Único **quando informado**: o índice único do MariaDB admite vários
 * `NULL`, então a coluna sozinha já expressa "cada documento pertence a
 * um cliente, e balcão pode não ter".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            // individual / company
            $table->string('type', 16);

            $table->string('name');

            // Só dígitos (value object `Support\Documento`): guardar com
            // máscara faria o mesmo CPF escrito de dois jeitos passar pela
            // unicidade.
            $table->string('doc', 14)->nullable();

            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();

            // Inscrição estadual — só PJ, e só quando contribuinte.
            $table->string('state_registration', 32)->nullable();

            /**
             * Metade da BR-301: "preço de atacado aplica-se a clientes
             * marcados como atacadistas **e/ou** pedidos acima de
             * quantidade mínima".
             *
             * O critério final está pendente de entrevista, mas a marca no
             * cliente é a parte que já se sabe necessária, e ela não muda
             * de forma conforme a outra metade for decidida.
             */
            $table->boolean('is_wholesale')->default(false);

            // erp / woo — não há `legacy`: o desktop nunca foi alimentado
            // (correção de 2026-07-25), então essa origem não existiria em
            // linha nenhuma.
            $table->string('origin', 16)->default('erp');

            $table->string('notes', 1000)->nullable();

            /**
             * Quando o titular consentiu (LGPD, pasta 25 §3).
             *
             * Nulável porque a base migrada do Woo não traz consentimento
             * registrado — e inventar uma data seria fabricar a prova de
             * um consentimento que ninguém coletou. Nulo aqui significa
             * "não temos registro", que é a verdade.
             */
            $table->timestamp('lgpd_consent_at')->nullable();

            $table->timestamps();

            // Cliente inativa, não some (mesmo espírito da BR-008): há
            // histórico de compra apontando para ele.
            $table->softDeletes();

            $table->unique('doc', 'uq_customers_doc');
            $table->index('name', 'idx_customers_name');
            $table->index('email', 'idx_customers_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
