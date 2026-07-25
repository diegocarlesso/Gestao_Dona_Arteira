<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de embalagens — BR-004, docs/32-Catalogo §3.6.
 *
 * A embalagem tem medidas próprias, distintas das da peça (BR-005): a
 * peça cabe na caixa, e é a caixa que viaja. É dela que sai a cotação de
 * frete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();

            $table->string('name');

            // Medidas da CAIXA. Decimal e não float: dimensão entra em
            // cálculo de frete, e float acumula erro (mesma razão do
            // ADR-0013 para dinheiro).
            $table->decimal('height_cm', 8, 2);
            $table->decimal('width_cm', 8, 2);
            $table->decimal('depth_cm', 8, 2);

            // Peso da embalagem vazia; somado ao da peça dá o peso postado.
            $table->decimal('weight_g', 10, 3)->default(0);

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index('active', 'idx_packages_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
