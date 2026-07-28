<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entrada bruta do Woo, persistida antes de processar — ADR-0007, docs/16 §4.
 *
 * O ADR-0007 decidiu "webhook persistido bruto + processamento assíncrono
 * deduplicado": grava-se o payload como ele chegou e responde-se 200 na
 * hora, para o site não segurar a conexão nem reenviar por timeout. O
 * processamento (casar itens, reservar) roda depois, em fila.
 *
 * A **puxada** (pull) grava aqui também, com `topic = order.pulled`: o
 * bruto guardado é a fonte para o corte 3 (fulfillment) extrair o endereço
 * de entrega sem bater na API de novo — e a trilha de tudo que entrou.
 *
 * Não é a idempotência do pedido (essa é o `integration_mappings`): é o
 * log de recebimento. `delivery_id` deduplica reentrega do **mesmo**
 * webhook; o mapeamento deduplica o **pedido**, venha por onde vier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woo_webhook_events', function (Blueprint $table) {
            $table->id();

            // order.created / order.updated / order.pulled / ping
            $table->string('topic', 32);

            // Id do pedido no Woo. Nulável: um ping não traz pedido.
            $table->string('remote_id', 64)->nullable();

            // Cabeçalho X-WC-Webhook-Delivery-ID: único por entrega. Deixa
            // reconhecer o reenvio que o Woo faz quando não recebe o 200 a
            // tempo — o mesmo evento chega duas vezes com o mesmo delivery.
            $table->string('delivery_id', 64)->nullable();

            // A assinatura HMAC conferiu? (BR-701). Guardado como fato: um
            // evento com assinatura inválida é registrado e recusado, não
            // apagado — investigar "quem tentou postar sem a chave" pede
            // que a tentativa tenha deixado rastro.
            $table->boolean('signature_valid')->default(false);

            // O payload como chegou, para reprocessar sem nova chamada.
            $table->json('payload');

            $table->timestamp('received_at');

            // Nulo enquanto não processou; a fila o carimba ao concluir.
            $table->timestamp('processed_at')->nullable();

            // O motivo de não ter processado, quando falhou — a operação lê
            // isto no painel de integrações (corte posterior).
            $table->string('error', 1000)->nullable();

            $table->timestamps();

            $table->index(['topic', 'remote_id'], 'idx_woo_events_topic_remote');
            $table->index('delivery_id', 'idx_woo_events_delivery');
            $table->index('processed_at', 'idx_woo_events_processed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woo_webhook_events');
    }
};
