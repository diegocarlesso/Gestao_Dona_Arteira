<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos do grupo IBS/CBS (UB12) no perfil fiscal — BR-608, ADR-0027.
 *
 * Nulável e sem seed, mesma filosofia da migration original de
 * `tax_profiles`: a tabela nasce vazia nesses 5 campos porque populá-los
 * é decisão do contador, não do deploy. Enquanto vazios,
 * `TaxProfile::ibscbsConfigurado()` diz falso e `MontarXmlNfe` omite o
 * grupo — Simples Nacional é isento até 04/01/2027 (LC 214/2025), então
 * omitir não é pendência hoje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table) {
            $table->string('ibscbs_cst', 4)->nullable()->after('csosn');
            $table->string('ibscbs_cclasstrib', 6)->nullable()->after('ibscbs_cst');
            $table->decimal('ibs_uf_aliquota', 7, 4)->nullable()->after('ibscbs_cclasstrib');
            $table->decimal('ibs_mun_aliquota', 7, 4)->nullable()->after('ibs_uf_aliquota');
            $table->decimal('cbs_aliquota', 7, 4)->nullable()->after('ibs_mun_aliquota');
        });
    }

    public function down(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'ibscbs_cst',
                'ibscbs_cclasstrib',
                'ibs_uf_aliquota',
                'ibs_mun_aliquota',
                'cbs_aliquota',
            ]);
        });
    }
};
