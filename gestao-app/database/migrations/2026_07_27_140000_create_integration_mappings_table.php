<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ponte entre entidade local e id externo — BR-704, docs/15 §2.5.
 *
 * É o que torna a carga re-executável (BR-706) e, depois do cutover, o
 * que torna a sincronização idempotente: sem ela, reprocessar um webhook
 * criaria um segundo produto em vez de atualizar o primeiro.
 *
 * Sem FK para o registro local de propósito: a tabela serve a qualquer
 * entidade (`entity_type`), e uma FK por tipo exigiria uma tabela por
 * tipo. O órfão é aceitável porque cadastro não se apaga (BR-008) — o
 * que existe aqui continua existindo lá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_mappings', function (Blueprint $table) {
            $table->id();

            // `product`, `product_category`, `customer`, `order`…
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('local_id');

            $table->string('remote_system', 32);
            $table->string('remote_id', 64);

            // Impressão do payload na última sincronização. Comparar antes
            // de enviar evita empurrar ao Woo um produto que não mudou —
            // e, na reconciliação, denuncia alteração feita fora do ERP
            // (BR-702).
            $table->string('checksum', 64)->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // A garantia de idempotência: um id externo mapeia para um
            // registro local só, por sistema e por tipo.
            $table->unique(
                ['remote_system', 'entity_type', 'remote_id'],
                'uq_integration_mappings_remoto'
            );

            // O caminho inverso — "qual é o id deste produto no Woo?" —
            // é a pergunta de toda sincronização de saída.
            $table->index(['entity_type', 'local_id'], 'idx_integration_mappings_local');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_mappings');
    }
};
