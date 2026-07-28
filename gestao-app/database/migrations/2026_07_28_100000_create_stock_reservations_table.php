<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservas de estoque — BR-203, docs/09-Estoque §4.
 *
 * Pedido confirmado reserva; expedição consome; cancelamento libera. A
 * reserva é o antídoto do oversell: `disponível = on_hand − reserved`
 * (BR-204), e é `reserved` que esta tabela alimenta.
 *
 * **Referência polimórfica, não `order_id`.** O modelo conceitual da
 * pasta 04 desenhou `order_id`, mas o ADR-0020 proíbe o Estoque
 * referenciar a tabela de Vendas — um módulo não conhece o esquema do
 * outro. `reference_type`/`reference_id` apontam para o pedido sem FK,
 * exatamente como `inventory_movements` já faz com sua origem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('product_id');
            $table->foreignId('location_id');

            $table->decimal('qty', 15, 3);

            // active / consumed / released
            $table->string('status', 16)->default('active');

            // Origem (o pedido, sem FK cross-módulo).
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->foreignId('created_by')->nullable();

            $table->timestamps();

            $table->foreign('product_id', 'fk_stock_reservations_product')
                ->references('id')->on('products')->restrictOnDelete();

            $table->foreign('location_id', 'fk_stock_reservations_location')
                ->references('id')->on('locations')->restrictOnDelete();

            $table->foreign('created_by', 'fk_stock_reservations_user')
                ->references('id')->on('users')->nullOnDelete();

            // "As reservas ativas deste produto/local" — o que a soma de
            // `qty_reserved` no saldo tem de refletir, e o que a
            // reconciliação vai conferir.
            $table->index(['product_id', 'location_id', 'status'], 'idx_stock_reservations_ativas');

            // "Que reservas este pedido tem?" — para liberar/consumir ao
            // cancelar ou expedir.
            $table->index(['reference_type', 'reference_id'], 'idx_stock_reservations_origem');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
