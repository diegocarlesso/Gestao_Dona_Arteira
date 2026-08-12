<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trilha de eventos do provedor sobre uma cobrança — BR-507,
 * docs/12-Financeiro/01-cobranca-e-boletos.md §4.
 *
 * `provider_event_id` UNIQUE é a idempotência inteira: um webhook
 * reprocessado ou reentregue pelo Mercado Pago tenta inserir a mesma
 * linha, a constraint recusa, e a baixa nunca duplica. Complementa (não
 * substitui) o `payload` bruto — aqui é o evento já identificado e ligado
 * à cobrança; o webhook cru fica só nos logs de aplicação (o Mercado Pago
 * não tem um `incoming_webhooks` genérico como o `woo_webhook_events`
 * porque o payload do webhook em si não traz o estado — só um `id` que
 * obriga a buscar o pagamento via GET antes de processar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_charge_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('charge_id')
                ->constrained('billing_charges')
                ->cascadeOnDelete();

            $table->string('type', 32);
            $table->string('provider_event_id', 64)->nullable()->unique();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('error', 500)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_charge_events');
    }
};
