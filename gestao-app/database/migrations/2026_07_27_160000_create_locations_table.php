<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Locais de estoque — docs/09-Estoque, ADR-0008.
 *
 * **Modelado desde já mesmo com um local só** (pasta 04). A empresa hoje
 * guarda tudo no ateliê, mas feira, consignação e loja aparecem no
 * domínio (pasta 30) — e acrescentar a dimensão `location_id` depois
 * significaria reescrever todo movimento já gravado para atribuir-lhe um
 * local retroativo, adivinhando qual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->string('name');

            // atelier / warehouse / store
            $table->string('type', 16);

            /**
             * O local que a tela assume quando não perguntam.
             *
             * Com um local só, obrigar a escolher seria perguntar o óbvio
             * a cada movimento; sem a coluna, a resposta viria de "o
             * primeiro que aparecer", que muda de sentido no dia em que
             * existir o segundo.
             */
            $table->boolean('is_default')->default(false);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
