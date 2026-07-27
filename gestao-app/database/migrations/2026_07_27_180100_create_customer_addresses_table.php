<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Endereços do cliente — vários por cliente (o Woo guardava um só).
 *
 * Separar entrega de cobrança importa para a NF-e: o destinatário do
 * documento fiscal e o destino da encomenda podem ser pessoas em cidades
 * diferentes, e é comum em presente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('customer_id');

            // "Casa", "Trabalho" — o que a pessoa reconhece na hora de
            // escolher, já que a rua sozinha não distingue nada para quem
            // atende no balcão.
            $table->string('label', 64)->nullable();

            $table->string('zip', 9);
            $table->string('street');
            $table->string('number', 32)->nullable();
            $table->string('complement', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('city', 120);

            // Sigla da UF: o IBGE tem 27, e nenhuma passa de duas letras.
            $table->char('state', 2);

            $table->boolean('is_default_shipping')->default(false);
            $table->boolean('is_default_billing')->default(false);

            $table->timestamps();

            $table->foreign('customer_id', 'fk_customer_addresses_customer')
                ->references('id')->on('customers')->cascadeOnDelete();

            $table->index(['customer_id', 'is_default_shipping'], 'idx_customer_addresses_entrega');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
