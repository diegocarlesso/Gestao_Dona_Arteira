<?php

declare(strict_types=1);

namespace App\Modules\Integrations\MercadoPago\Services;

/**
 * Confere a assinatura do webhook do Mercado Pago — ADR-0018 §3.1, BR-701.
 *
 * O Mercado Pago manda `x-signature: ts=1710000000,v1=abc123...` e exige
 * remontar um "manifest" com o `id` do recurso, o `X-Request-Id` do
 * request e o `ts` recebido, assinar com HMAC-SHA256 usando o segredo do
 * painel e comparar. Diferente do Woo (que assina o corpo inteiro), aqui
 * é uma string curta montada a partir de três valores — mas o princípio é
 * o mesmo: sem segredo configurado, recusa tudo (`VerificaAssinaturaWoo`).
 *
 * Comparação com `hash_equals` (tempo constante) pelo mesmo motivo de lá.
 */
class VerificaAssinaturaMercadoPago
{
    public function confere(?string $headerXSignature, ?string $headerXRequestId, string $dataId): bool
    {
        $segredo = (string) config('integrations.mercadopago.webhook_secret');

        if ($segredo === '' || $headerXSignature === null || $headerXSignature === '') {
            return false;
        }

        [$ts, $v1] = $this->extrairTsEV1($headerXSignature);

        if ($ts === null || $v1 === null) {
            return false;
        }

        $manifest = "id:{$dataId};request-id:{$headerXRequestId};ts:{$ts};";
        $esperada = hash_hmac('sha256', $manifest, $segredo);

        return hash_equals($esperada, $v1);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function extrairTsEV1(string $header): array
    {
        $partes = [];

        foreach (explode(',', $header) as $par) {
            [$chave, $valor] = array_pad(explode('=', $par, 2), 2, null);

            if ($chave !== null && $valor !== null) {
                $partes[trim($chave)] = trim($valor);
            }
        }

        return [$partes['ts'] ?? null, $partes['v1'] ?? null];
    }
}
