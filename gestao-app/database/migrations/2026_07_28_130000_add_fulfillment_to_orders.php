<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de expedição no pedido — BR-303, docs/10-Vendas §5 (corte 3).
 *
 * O **quando** de expedir e entregar mora aqui (a trilha completa de
 * transições está em `order_status_history`; estes são os marcos que as
 * telas e os relatórios leem sem varrer o histórico). Rastreio e
 * transportadora entram à mão neste corte — Melhor Envio (etiqueta e
 * cálculo) é a fase 6, e até lá o expedidor informa o que despachou.
 *
 * Expand-only (pasta 04): colunas novas, nuláveis, nada reescrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('shipped_at')->nullable()->after('confirmed_at');
            $table->timestamp('delivered_at')->nullable()->after('shipped_at');

            // Rastreio informado na expedição; devolvido ao Woo no corte 4.
            $table->string('tracking_code', 100)->nullable()->after('delivered_at');
            $table->string('carrier', 100)->nullable()->after('tracking_code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipped_at', 'delivered_at', 'tracking_code', 'carrier']);
        });
    }
};
