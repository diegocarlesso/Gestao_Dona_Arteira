<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorias em árvore — BR-007, docs/32-Catalogo §3.4.
 *
 * Espelha a árvore do WooCommerce na migração. Um nível de `parent_id`
 * basta para categoria → subcategoria; a navegação por ancestrais é
 * problema da consulta, não de colunas desnormalizadas que precisariam
 * ser mantidas em sincronia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();

            $table->string('name');
            $table->string('slug')->unique();

            $table->foreignId('parent_id')->nullable();

            $table->timestamps();

            // RESTRICT: apagar uma categoria que tem filhas deixaria
            // órfãs apontando para o nada. Reorganizar exige mover as
            // filhas antes — explicitamente, não por efeito colateral.
            $table->foreign('parent_id', 'fk_product_categories_parent')
                ->references('id')->on('product_categories')->restrictOnDelete();

            $table->index('parent_id', 'idx_product_categories_parent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
