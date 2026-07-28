<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

/**
 * Confere a assinatura HMAC do webhook do Woo — BR-701, docs/16 §2.
 *
 * O Woo assina o **corpo cru** do POST com HMAC-SHA256 usando a chave
 * secreta do webhook e manda o resultado em base64 no cabeçalho
 * `X-WC-Webhook-Signature`. Recalcular e comparar é o que prova que o
 * pedido veio da loja, e não de alguém postando no endereço público.
 *
 * Comparação com `hash_equals` (tempo constante): comparar string comum
 * vazaria, byte a byte, quanto do prefixo bateu — o suficiente para
 * adivinhar a assinatura por tentativa. Sem chave configurada, recusa
 * tudo: um webhook que aceita sem conferir é uma porta sem fechadura.
 */
class VerificaAssinaturaWoo
{
    public function confere(string $corpo, ?string $assinatura): bool
    {
        $segredo = (string) config('integrations.woocommerce.webhook_secret');

        if ($segredo === '' || $assinatura === null || $assinatura === '') {
            return false;
        }

        $esperada = base64_encode(hash_hmac('sha256', $corpo, $segredo, true));

        return hash_equals($esperada, $assinatura);
    }
}
