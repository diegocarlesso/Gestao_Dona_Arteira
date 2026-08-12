<?php

declare(strict_types=1);

namespace App\Modules\Integrations\MercadoPago\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integrations\MercadoPago\Jobs\ProcessarWebhookMercadoPago;
use App\Modules\Integrations\MercadoPago\Services\VerificaAssinaturaMercadoPago;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recebe as notificações de pagamento do Mercado Pago — ADR-0007,
 * ADR-0018 §3.1.
 *
 * O corpo do webhook **não traz o status real** — só `{type, data:{id}}` —
 * então o trabalho pesado (buscar o pagamento, mapear status, baixar o
 * título) vai inteiro para o job; aqui só confere a assinatura e responde
 * rápido. Mesmo desenho do `WooWebhookController`.
 *
 * Sem `web`: quem posta é o servidor do Mercado Pago, não um navegador —
 * a autenticação é o header `x-signature` (BR-701), não o cookie.
 */
class MercadoPagoWebhookController extends Controller
{
    public function __invoke(Request $request, VerificaAssinaturaMercadoPago $assinaturas): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), true) ?: [];

        $tipo = (string) ($payload['type'] ?? '');
        $paymentId = (string) data_get($payload, 'data.id', '');

        $valida = $assinaturas->confere(
            $request->header('x-signature'),
            $request->header('x-request-id'),
            $paymentId,
        );

        if (! $valida) {
            return response()->json(['erro' => 'assinatura inválida'], 401);
        }

        if ($tipo !== 'payment' || $paymentId === '') {
            // Evento assinado mas que não é sobre um pagamento (ou sem
            // `data.id`, malformado) — aceito e encerrado, nada a buscar.
            return response()->json(['ok' => true]);
        }

        $providerEventId = isset($payload['id']) ? (string) $payload['id'] : null;

        ProcessarWebhookMercadoPago::dispatch($paymentId, $providerEventId);

        return response()->json(['ok' => true], 202);
    }
}
