<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staging bruto dos clientes do WooCommerce — docs/17-Migracao F2.
 *
 * Mesma convenção do `stg_woo_products` ([pasta 04 §39]): prefixo `stg_`,
 * **sem FKs**, colunas espelhando a origem mais as de controle. O dado
 * bruto não precisa ser válido para entrar — ele entra para ser examinado,
 * e quem julga é a triagem (F3).
 *
 * ## Por que `orders_count` merece coluna própria
 *
 * É o **critério de rejeição** da triagem: dos 198 cadastros do site,
 * apenas 62 compraram alguma vez ([pasta 31 §2](../../../docs/31-Inventario-Legado/06-clientes.md)),
 * e a decisão da diretoria de 2026-08-10 é migrar só esses — minimização
 * de dado pessoal (LGPD, [pasta 25 §3](../../../docs/25-Seguranca/README.md)).
 * Perguntar "quantos serão rejeitados" não pode exigir varrer JSON linha a
 * linha, então a contagem fica ao lado, consultável.
 *
 * Os endereços (`billing`/`shipping`) e o CPF (metadado do plugin
 * brasileiro) ficam **só** no `payload`: são lidos uma vez na carga, não
 * são critério de triagem, e espalhá-los em colunas seria decidir agora o
 * formato de um dado que ninguém filtra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stg_woo_customers', function (Blueprint $table) {
            $table->id();

            // Chave natural da origem — é por ela que o upsert deduplica,
            // tornando a extração re-executável (BR-706).
            $table->unsignedBigInteger('woo_id')->unique();

            $table->string('email')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();

            // Nulável, e não `default(0)`: ausência do campo na resposta é
            // diferente de "nunca comprou". A triagem trata os dois casos
            // de forma diferente — zero rejeita, nulo fica pendente, porque
            // rejeitar por dado ausente descartaria um comprador em
            // silêncio.
            $table->unsignedInteger('orders_count')->nullable();

            // Data de modificação na origem, para extração incremental por
            // `modified_after` nas re-execuções.
            $table->timestamp('woo_modified_at')->nullable();

            // O JSON como veio — inclusive `billing`, `shipping` e
            // `meta_data`. É o que permite reprocessar o saneamento e a
            // carga sem bater na API de novo, o que importa mais aqui do
            // que no catálogo: cada chamada extra é uma leitura a mais de
            // dado pessoal.
            $table->json('payload');

            // --- triagem (F3) ---
            $table->string('status_triagem', 20)->default('pending');
            $table->text('motivo_triagem')->nullable();

            // O que a triagem propõe, para a carga não redecidir: nome
            // composto (com o mesmo recuo do `ResolveCustomerService`) e
            // documento garimpado dos metadados do plugin de checkout.
            $table->string('nome_proposto')->nullable();
            $table->string('doc_proposto', 14)->nullable();

            // --- controle (pasta 04 §39) ---
            $table->unsignedBigInteger('import_batch')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index('import_batch', 'idx_stg_woo_customers_batch');
            $table->index('status_triagem', 'idx_stg_woo_customers_triagem');
            $table->index('email', 'idx_stg_woo_customers_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stg_woo_customers');
    }
};
