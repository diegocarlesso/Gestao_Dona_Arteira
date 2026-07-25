<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O cadastro de produtos — docs/32-Catalogo, ADR-0022.
 *
 * Uma tabela para peça acabada, matéria-prima, embalagem, revenda e
 * insumo, distinguidas por `kind` — um só estoque (BR-207).
 *
 * **Não há coluna de saldo.** Quantidade é do ledger da pasta 09: todo
 * movimento passa por `inventory_movements`, e um `products.stock` aqui
 * seria a segunda fonte de verdade que o ADR-0008 existe para impedir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // ULID público: é o route key. O id sequencial não sai daqui
            // (convenção da pasta 04) — ao contrário do SKU, que é código
            // de negócio e aparece na tela.
            $table->string('public_id', 26)->unique();

            // BR-002: único e IMUTÁVEL. Formato DA-0001 (ADR-0022),
            // sequencial e sem significado embutido — código com
            // classificação dentro passa a mentir quando a classificação
            // muda, e a regra proíbe corrigi-lo.
            $table->string('sku', 20)->unique();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // finished_good / raw_material / packaging / resale / supply.
            // VARCHAR + enum PHP, nunca ENUM MySQL (pasta 04).
            $table->string('kind', 32);
            $table->string('unit', 8)->default('UN');
            $table->string('status', 16)->default('active');

            // O acabamento de pintura manual (ADR-0022 §3.2): é o que
            // distingue dois produtos irmãos. Descritivo, não eixo de um
            // produto-pai.
            //
            // A "altura" do Woo (7 faixas) NÃO vira coluna: `height_cm`
            // abaixo já carrega o fato, e uma faixa derivada seria uma
            // segunda coluna dizendo o mesmo — que um dia discordaria.
            $table->string('color')->nullable();

            // Medidas da PEÇA (BR-005), distintas das da embalagem.
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->decimal('width_cm', 8, 2)->nullable();
            $table->decimal('depth_cm', 8, 2)->nullable();
            $table->decimal('weight_g', 10, 3)->nullable();

            // Dados fiscais que descrevem a MERCADORIA (pasta 32 §3.7).
            // As regras de tributação são da pasta 13 e dependem da
            // operação, não do produto.
            $table->string('ncm', 8)->nullable();
            $table->string('cest', 7)->nullable();
            $table->string('origin', 1)->nullable();
            // Nulável porque peça artesanal quase nunca tem código de
            // barras — a NF-e aceita "SEM GTIN".
            $table->string('gtin', 14)->nullable();

            $table->foreignId('product_category_id')->nullable();
            $table->foreignId('default_package_id')->nullable();

            // Quantidade: DECIMAL(15,3) pela convenção da pasta 04.
            $table->decimal('min_stock', 15, 3)->default(0);

            // Lead de secagem, insumo do planejamento de produção (pasta 08).
            $table->unsignedSmallInteger('drying_days')->nullable();

            $table->boolean('sell_on_woo')->default(false);

            $table->timestamps();

            // RESTRICT em ambas: categoria ou embalagem em uso não some
            // por baixo do produto. Cadastro não se apaga (BR-008).
            $table->foreign('product_category_id', 'fk_products_category')
                ->references('id')->on('product_categories')->restrictOnDelete();

            $table->foreign('default_package_id', 'fk_products_package')
                ->references('id')->on('packages')->restrictOnDelete();

            // "O catálogo ativo desta categoria" — a consulta da listagem.
            $table->index(['status', 'product_category_id'], 'idx_products_status_category');
            $table->index('kind', 'idx_products_kind');
            $table->index('sell_on_woo', 'idx_products_woo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
