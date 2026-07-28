<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integrations\WooCommerce\Jobs\ProcessWooOrder;
use App\Modules\Integrations\WooCommerce\Models\WooWebhookEvent;
use App\Modules\Integrations\WooCommerce\Services\VerificaAssinaturaWoo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recebe os webhooks de pedido do WooCommerce — ADR-0007, docs/16 §4.
 *
 * Faz o mínimo e devolve: confere a assinatura, **grava o bruto** e
 * enfileira o processamento. Responder na hora é o que impede o site de
 * segurar a conexão e reenviar por timeout — o trabalho pesado é do job.
 *
 * Sem `web`, logo sem CSRF nem sessão: quem chama é servidor, não
 * navegador, e a autenticação é a assinatura HMAC (BR-701), não o cookie.
 */
class WooWebhookController extends Controller
{
    public function __invoke(Request $request, VerificaAssinaturaWoo $assinaturas): JsonResponse
    {
        $corpo = $request->getContent();
        $assinatura = $request->header('X-WC-Webhook-Signature');
        $topico = (string) $request->header('X-WC-Webhook-Topic', 'order.updated');

        /** @var array<string, mixed> $payload */
        $payload = json_decode($corpo, true) ?: [];

        $valida = $assinaturas->confere($corpo, $assinatura);

        // Ping de ativação: ao criar o webhook, o Woo posta {"webhook_id":N}
        // sem assinatura. Recusá-lo faria a ativação falhar no painel do
        // site; ele não é pedido, então é registrado e respondido 200.
        $ehPing = ! isset($payload['line_items']) && isset($payload['webhook_id']);

        $evento = WooWebhookEvent::query()->create([
            'topic' => $ehPing ? 'ping' : $topico,
            'remote_id' => isset($payload['id']) ? (string) $payload['id'] : null,
            'delivery_id' => $request->header('X-WC-Webhook-Delivery-ID'),
            'signature_valid' => $valida,
            'payload' => $payload,
            'received_at' => now(),
            'processed_at' => $ehPing ? now() : null,
        ]);

        if ($ehPing) {
            return response()->json(['ok' => true]);
        }

        if (! $valida) {
            // Registrado acima (com signature_valid=false) e recusado: a
            // tentativa sem chave válida deixa rastro para investigação.
            return response()->json(['erro' => 'assinatura inválida'], 401);
        }

        if (! isset($payload['line_items'])) {
            // Evento que não é pedido (ex.: outro tópico assinado). Aceito e
            // encerrado — nada a importar.
            $evento->marcarProcessado('sem itens de pedido');

            return response()->json(['ok' => true]);
        }

        ProcessWooOrder::dispatch($evento->id);

        return response()->json(['ok' => true], 202);
    }
}
