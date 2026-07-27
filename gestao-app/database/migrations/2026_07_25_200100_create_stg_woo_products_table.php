<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staging bruto dos produtos do WooCommerce — docs/17-Migracao F2.
 *
 * Convenção da [pasta 04 §39]: prefixo `stg_`, **sem FKs**, colunas
 * espelhando a origem mais as de controle (`import_batch`, `status`,
 * `error`). Sem FK porque o dado bruto não tem de ser válido para entrar
 * — ele entra para ser examinado, e é no saneamento (F3) que se decide o
 * que vira produto.
 *
 * O `payload` guarda o JSON inteiro que a API devolveu. As colunas ao
 * lado são só as que a triagem precisa consultar: descobrir "quantos
 * produtos sem peso" não pode exigir varrer JSON linha a linha, e
 * reprocessar depois de um bug no adapter não pode exigir buscar tudo de
 * novo na API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stg_woo_products', function (Blueprint $table) {
            $table->id();

            // Chave natural da origem — é por ela que o upsert deduplica,
            // tornando a extração re-executável (BR-706).
            $table->unsignedBigInteger('woo_id')->unique();

            // `simple`, `variable` ou `variation`. Pelo ADR-0022, tanto
            // `simple` quanto `variation` viram produto próprio no ERP; o
            // `variable` é só o guarda-chuva que o Woo usa para agrupar, e
            // não tem correspondente no nosso modelo.
            $table->string('type', 20)->nullable();
            $table->unsignedBigInteger('parent_woo_id')->nullable();

            $table->string('name')->nullable();

            // Nulo em 716 de 716 produtos, conforme o inventário da pasta
            // 31. A coluna existe porque a origem tem o campo — o vazio é
            // o achado, não a ausência da coluna.
            $table->string('sku')->nullable();

            $table->string('status', 20)->nullable();
            $table->string('regular_price', 32)->nullable();
            $table->string('weight', 32)->nullable();

            // Data de modificação na origem, para extração incremental por
            // `modified_after` nas re-execuções.
            $table->timestamp('woo_modified_at')->nullable();

            // O JSON como veio. É o que permite reprocessar o saneamento
            // sem tocar na API de novo.
            $table->json('payload');

            // --- controle (pasta 04 §39) ---
            $table->unsignedBigInteger('import_batch')->nullable();
            $table->string('status_triagem', 20)->default('pending');
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index('import_batch', 'idx_stg_woo_products_batch');
            $table->index('type', 'idx_stg_woo_products_type');
            $table->index('status_triagem', 'idx_stg_woo_products_triagem');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stg_woo_products');
    }
};
