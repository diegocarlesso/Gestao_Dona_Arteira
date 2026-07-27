<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O nome que o produto terá no ERP — docs/17-Migracao F3.
 *
 * Nem sempre é o nome que veio do Woo, e a diferença apareceu só ao olhar
 * os dados: as variações de kit **não herdam o nome do pai**, recebem o
 * rótulo da opção. Há 18 variações chamadas "KIT COMPLETO" e 11 chamadas
 * "BUDA SIDARTA", cada uma de um kit diferente.
 *
 * Carregadas como vieram, o catálogo teria 18 linhas idênticas e sem
 * sentido. A coluna guarda o nome composto — "<peça> — KIT COMPLETO" —
 * separado do `name` bruto, que continua sendo o que a origem disse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stg_woo_products', function (Blueprint $table) {
            $table->string('nome_proposto')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('stg_woo_products', function (Blueprint $table) {
            $table->dropColumn('nome_proposto');
        });
    }
};
