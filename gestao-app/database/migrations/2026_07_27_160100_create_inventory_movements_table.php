<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O ledger de estoque — BR-202, ADR-0008.
 *
 * **Append-only.** Nenhuma linha daqui é alterada ou apagada depois de
 * gravada: estorno é contra-movimento que referencia o original. É o que
 * permite responder "por que o saldo está assim?" — a pergunta que o
 * legado não respondia, porque guardava estoque como um inteiro mutável.
 *
 * O saldo em `inventory_balances` é materialização desta tabela, não a
 * verdade: a verdade é a soma daqui, e o job de reconciliação existe para
 * provar que as duas concordam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('product_id');
            $table->foreignId('location_id');

            // purchase_receipt, production_input, production_output,
            // sale_shipment, return_in, adjustment_in, adjustment_out,
            // transfer_in, transfer_out, loss
            $table->string('type', 24);

            /**
             * Quantidade **com sinal**: entrada positiva, saída negativa.
             *
             * O sinal sai do tipo, não de quem chama (`MovementType::sinal()`)
             * — assim ninguém registra uma venda que aumenta o estoque por
             * ter esquecido o menos. E guardar com sinal faz do saldo uma
             * soma simples, que é o que a reconciliação precisa conferir.
             */
            $table->decimal('qty', 15, 3);

            /**
             * Custo unitário da entrada (BR-206), em DECIMAL(15,2) como
             * todo dinheiro (ADR-0013).
             *
             * Nulo nas saídas e nos ajustes: saída consome o custo médio
             * corrente, e contagem física não tem custo — ela corrige
             * quantidade, não valor.
             */
            $table->decimal('unit_cost', 15, 2)->nullable();

            // Origem polimórfica: OP, pedido, compra, contagem. Sem FK,
            // porque aponta para tabelas de módulos que ainda não existem
            // — a integridade aqui é responsabilidade de quem grava.
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // O motivo, quando o tipo o exige (ajuste, perda — BR-205).
            $table->string('notes', 500)->nullable();

            /**
             * Quando aconteceu **no mundo**, que não é quando foi digitado.
             *
             * Uma quebra de sábado lançada na segunda pertence a sábado; o
             * extrato ordena por aqui, e `created_at` continua registrando
             * o momento do lançamento para a auditoria.
             */
            $table->timestamp('occurred_at');

            $table->foreignId('created_by')->nullable();

            $table->timestamps();

            $table->foreign('product_id', 'fk_inventory_movements_product')
                ->references('id')->on('products')->restrictOnDelete();

            $table->foreign('location_id', 'fk_inventory_movements_location')
                ->references('id')->on('locations')->restrictOnDelete();

            $table->foreign('created_by', 'fk_inventory_movements_user')
                ->references('id')->on('users')->nullOnDelete();

            // O extrato por produto (pasta 04). É a tela de depuração
            // número 1 do módulo, então o índice cobre a consulta dela na
            // ordem exata em que ela filtra e ordena.
            $table->index(['product_id', 'location_id', 'occurred_at'], 'idx_inventory_movements_extrato');

            // "Que movimentos esta OP/este pedido gerou?" — a pergunta do
            // estorno e da conferência.
            $table->index(['reference_type', 'reference_id'], 'idx_inventory_movements_origem');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
