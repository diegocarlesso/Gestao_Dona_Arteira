<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preços de lista com histórico — BR-003, docs/32-Catalogo §3.5.
 *
 * Duas listas: `retail` e `wholesale`. A empresa vende nos dois canais
 * (confirmado pelo dono em 2026-07-25), mas **o preço de atacado nunca
 * foi registrado em sistema nenhum** — o site é canal de varejo e o
 * desktop nunca operou. A equipe preencherá produto a produto; até lá a
 * lista `wholesale` simplesmente não tem linha, e a listagem sinaliza a
 * lacuna.
 *
 * **Preço novo é linha nova, nunca UPDATE.** Um pedido de seis meses
 * atrás precisa continuar explicável, e isso exige saber quanto a peça
 * custava naquele dia — sobrescrever apagaria a única prova disso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id');

            // retail / wholesale
            $table->string('price_list', 16);

            // DECIMAL(15,2) + brick/money no PHP (ADR-0013). Dinheiro
            // nunca em float.
            $table->decimal('price', 15, 2);

            $table->timestamp('valid_from')->useCurrent();

            $table->timestamps();

            // CASCADE aqui, ao contrário do resto do módulo: o preço não
            // tem vida própria sem o produto. Mas produto não se apaga
            // (BR-008, vira `archived`), então na prática isto nunca
            // dispara — está no lugar certo caso um dia dispare.
            $table->foreign('product_id', 'fk_product_prices_product')
                ->references('id')->on('products')->cascadeOnDelete();

            // "O preço vigente desta lista para este produto": ordenar por
            // valid_from desc e pegar o primeiro. É a consulta de toda
            // venda, então o índice cobre as três colunas na ordem certa.
            $table->index(['product_id', 'price_list', 'valid_from'], 'idx_product_prices_vigente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
