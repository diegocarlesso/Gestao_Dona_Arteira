<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staging bruto das categorias do WooCommerce — docs/17-Migracao F2.
 *
 * Carregadas antes dos produtos na F4 ("ordem por dependência:
 * categorias → produtos…"), mas extraídas do mesmo jeito: bruto primeiro,
 * decisão depois.
 *
 * A árvore do Woo mistura tema com sazonal (`DIA DAS MÃES`), material
 * (`MDF`) e merchandising (`HOME SITE`) — a recomendação RC-06 da pasta
 * 31 é reclassificar. Por isso `parent_woo_id` vem como veio, e o
 * de-para é decisão do saneamento, não da extração.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stg_woo_categories', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('woo_id')->unique();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();

            // Zero no Woo significa "raiz". Guardado como veio; traduzir
            // para NULL é trabalho da carga, não da extração.
            $table->unsignedBigInteger('parent_woo_id')->nullable();

            $table->unsignedInteger('count')->nullable();

            $table->json('payload');

            // --- controle (pasta 04 §39) ---
            $table->unsignedBigInteger('import_batch')->nullable();
            $table->string('status_triagem', 20)->default('pending');
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index('import_batch', 'idx_stg_woo_categories_batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stg_woo_categories');
    }
};
