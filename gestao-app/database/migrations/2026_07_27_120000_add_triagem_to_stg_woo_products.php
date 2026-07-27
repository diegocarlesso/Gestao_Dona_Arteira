<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisões do saneamento sobre cada linha do staging — docs/17-Migracao F3.
 *
 * Ficam **na própria linha** e não em tabela à parte porque são dado de
 * controle, que é justamente o que a [pasta 04 §39] manda o `stg_`
 * carregar ao lado das colunas da origem. Uma tabela de propostas
 * exigiria join para responder "o que acontece com este produto", que é a
 * única pergunta que o saneamento faz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stg_woo_products', function (Blueprint $table) {
            // O SKU que este item receberá na carga. Proposto aqui e
            // **aprovado por gente** antes da F4 — a BR-002 torna o código
            // imutável, então errar aqui é errar para sempre.
            $table->string('sku_proposto', 20)->nullable()->after('sku');

            // Nulo enquanto ninguém conferiu. A carga (F4) recusa o que
            // não estiver aprovado.
            $table->timestamp('aprovado_em')->nullable()->after('status_triagem');

            // Por que este item foi classificado assim — texto curto, para
            // a listagem de triagem explicar sem exigir ler código.
            $table->string('motivo_triagem')->nullable()->after('aprovado_em');

            $table->index('sku_proposto', 'idx_stg_woo_products_sku_proposto');
        });
    }

    public function down(): void
    {
        Schema::table('stg_woo_products', function (Blueprint $table) {
            $table->dropIndex('idx_stg_woo_products_sku_proposto');
            $table->dropColumn(['sku_proposto', 'aprovado_em', 'motivo_triagem']);
        });
    }
};
