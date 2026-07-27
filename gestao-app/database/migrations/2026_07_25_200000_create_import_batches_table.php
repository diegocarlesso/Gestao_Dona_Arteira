<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lotes de importação — docs/17-Migracao §2, princípio 5.
 *
 * "Cada lote tem `import_batch` rastreável; números de controle
 * conferidos (contagens origem × destino)." Sem esta tabela, uma
 * extração que trouxe 700 de 716 produtos seria indistinguível de uma que
 * trouxe tudo — e a validação da F5 compara justamente contagens.
 *
 * Fica fora do prefixo `stg_` de propósito: as tabelas de staging são
 * descartáveis depois da carga, e o histórico de quem rodou o quê e
 * quando não é.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();

            // `woocommerce` hoje; o pipeline é reutilizável para futuras
            // importações em massa (pasta 17 §6).
            $table->string('source', 32);
            $table->string('entity', 32);

            // running / completed / failed
            $table->string('status', 16)->default('running');

            // Rodada de teste não grava nada, mas o registro do lote fica —
            // é como se sabe que alguém conferiu antes de valer.
            $table->boolean('dry_run')->default(false);

            // Contagens de controle da F5. `fetched` é o que a origem
            // devolveu; `stored` o que entrou no staging. Diferença entre
            // os dois é exatamente o que precisa de explicação.
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('stored')->default(0);
            $table->unsignedInteger('failed')->default(0);

            // Até onde foi, para retomar sem recomeçar (lotes pequenos e
            // retomáveis, por causa dos limites do shared hosting).
            $table->unsignedInteger('last_page')->default(0);

            $table->text('error')->nullable();

            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['source', 'entity', 'created_at'], 'idx_import_batches_fonte');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
