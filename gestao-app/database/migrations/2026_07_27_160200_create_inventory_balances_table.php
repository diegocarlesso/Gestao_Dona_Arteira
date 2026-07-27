<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo materializado por produto e local — ADR-0008.
 *
 * **Otimização, não fonte da verdade.** A verdade é a soma de
 * `inventory_movements`; esta tabela existe porque calcular a
 * disponibilidade a cada consulta viraria um `SUM` sobre milhões de
 * linhas ao longo dos anos. Atualizada na mesma transação do movimento,
 * com lock — e conferida por reconciliação, que é o preço de materializar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id');
            $table->foreignId('location_id');

            $table->decimal('qty_on_hand', 15, 3)->default(0);

            /**
             * Reservado por pedido confirmado (BR-203).
             *
             * A coluna nasce aqui, embora Vendas só chegue no Gate 02:
             * `disponível = on_hand − reserved` é a conta que o site
             * consome (BR-204), e deixá-la para depois faria a fórmula
             * mudar de forma justamente quando houver dado real em jogo.
             */
            $table->decimal('qty_reserved', 15, 3)->default(0);

            /**
             * Custo médio móvel (BR-206), recalculado a cada entrada com
             * custo. Em DECIMAL(15,2) como todo dinheiro (ADR-0013).
             *
             * O arredondamento a cada recálculo introduz erro de até meio
             * centavo por operação, e ele **não** se acumula: a média é
             * reancorada pelo custo real de cada entrada nova. Guardar
             * mais casas exigiria abrir exceção à convenção da pasta 04
             * para ganhar precisão que peça de gesso não usa.
             */
            $table->decimal('avg_cost', 15, 2)->default(0);

            $table->timestamps();

            $table->foreign('product_id', 'fk_inventory_balances_product')
                ->references('id')->on('products')->restrictOnDelete();

            $table->foreign('location_id', 'fk_inventory_balances_location')
                ->references('id')->on('locations')->restrictOnDelete();

            // Um saldo por produto e local — e é esta unicidade que
            // sustenta o lock: duas transações concorrentes disputam a
            // mesma linha em vez de criarem duas.
            $table->unique(['product_id', 'location_id'], 'uq_inventory_balances_produto_local');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_balances');
    }
};
