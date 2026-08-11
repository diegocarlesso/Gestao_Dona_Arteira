<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Endereço de entrega/cobrança do pedido — BR-707.
 *
 * Separado de `customer_addresses` de propósito: a entrega às vezes não é
 * no endereço do próprio comprador (presente para outra pessoa). Se o
 * pedido só apontasse para o endereço padrão do cliente, um presente
 * compraria a peça certa e entregaria no lugar errado — e a NF-e sairia
 * com o destinatário errado junto.
 *
 * Mesma estrutura de `customer_addresses`, sem o vínculo fixo a um
 * cliente: aqui o vínculo é o pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_addresses', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('order_id');

            // 'billing' ou 'shipping' — mesmo par que o Woo manda, um
            // registro de cada por pedido no máximo (UNIQUE abaixo).
            $table->string('type', 16);

            $table->string('zip', 9);
            $table->string('street');
            $table->string('number', 32)->nullable();
            $table->string('complement', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('city', 120);
            $table->char('state', 2);

            // Nulável pelo mesmo motivo de `customer_addresses.city_code`
            // (ADR-0026): nasce nulo até a resolução automática casar
            // contra `ibge_municipalities`, e fica nulo — pendência
            // visível, nunca aproximação — quando a grafia não bate.
            $table->char('city_code', 7)->nullable();

            $table->timestamps();

            $table->foreign('order_id', 'fk_order_addresses_order')
                ->references('id')->on('orders')->cascadeOnDelete();

            $table->foreign('city_code', 'fk_order_addresses_municipio')
                ->references('ibge_code')->on('ibge_municipalities')
                ->restrictOnDelete();

            $table->unique(['order_id', 'type'], 'uq_order_addresses_tipo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_addresses');
    }
};
