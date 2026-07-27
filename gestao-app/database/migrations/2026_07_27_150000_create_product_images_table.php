<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imagens do produto — [ADR-0017](../../docs/27-ADR/ADR-0017-midia-canonica.md), fase 1.
 *
 * **Referência, não arquivo.** A mídia continua hospedada no WordPress
 * até o Gate 06; o ERP guarda a URL e o id remoto, e exibe. Migrar as
 * imagens agora custaria disco, pipeline de redimensionamento e reenvio
 * ao Woo — que precisa delas localmente para a loja renderizar.
 *
 * A dívida é conhecida e tem prazo: o catálogo é o mestre (ADR-0006) mas
 * referencia mídia hospedada no canal. Inversão tolerada e datada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id');

            $table->string('url', 1023);

            // O Woo já devolve uma versão reduzida. Usar a completa numa
            // prévia de listagem faria o navegador baixar a foto inteira
            // para exibir num quadro de 200 px.
            $table->string('thumbnail_url', 1023)->nullable();

            // Texto alternativo da origem — acessibilidade não se
            // reconstrói depois, e a origem já tem o dado.
            $table->string('alt')->nullable();

            // `woo` hoje; `erp` quando a fase 2 do ADR-0017 chegar. É esta
            // coluna que dirá o que ainda depende do WordPress.
            $table->string('source', 16)->default('woo');
            $table->string('remote_id', 64)->nullable();

            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            // CASCADE: imagem não tem vida própria sem o produto. Mas
            // produto não se apaga (BR-008), então na prática não dispara.
            $table->foreign('product_id', 'fk_product_images_product')
                ->references('id')->on('products')->cascadeOnDelete();

            // "A imagem principal deste produto" — a consulta da listagem.
            $table->index(['product_id', 'position'], 'idx_product_images_produto_pos');

            // Recarregar a migração não pode duplicar imagem (BR-706).
            $table->unique(['product_id', 'source', 'remote_id'], 'uq_product_images_remoto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
