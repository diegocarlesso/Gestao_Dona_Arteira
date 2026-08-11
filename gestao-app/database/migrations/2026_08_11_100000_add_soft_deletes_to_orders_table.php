<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exclusão de pedido — BR-311.
 *
 * Mesmo padrão do cadastro de clientes (`customers.deleted_at`): o pedido
 * sai das listagens, mas a linha, os itens e o histórico continuam no
 * banco. Só se aplica a partir de `Cancelado` e só quando `nfe_status` é
 * nulo — a checagem é do `DeleteOrderService`, não do banco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
