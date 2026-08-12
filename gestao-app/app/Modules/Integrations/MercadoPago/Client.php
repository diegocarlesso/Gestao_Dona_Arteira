<?php

declare(strict_types=1);

namespace App\Modules\Integrations\MercadoPago;

use App\Modules\Integrations\MercadoPago\Exceptions\IntegracaoMercadoPagoIndisponivel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fala com a API de Pagamentos do Mercado Pago — docs/15-Integracoes §3,
 * ADR-0018 §3.1.
 *
 * Mesmo desenho do `WooCommerce\Client`: autentica, chama, devolve array.
 * Não sabe o que é PIX ou boleto — traduzir é do `MercadoPagoGateway`
 * (Adapters). Mesma URL para ambiente de teste e produção; a diferença é
 * só a credencial configurada (prefixo `TEST_` ou não).
 */
class Client
{
    private const BASE_URL = 'https://api.mercadopago.com/';

    public function isEnabled(): bool
    {
        return (bool) config('integrations.mercadopago.enabled');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function criarPagamento(array $payload, string $idempotencyKey): array
    {
        $this->garantirConfiguracao();

        $resposta = $this->request()
            ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->post($this->url('v1/payments'), $payload);

        return $this->corpo($resposta);
    }

    /**
     * @return array<string, mixed>
     */
    public function consultarPagamento(string $paymentId): array
    {
        $this->garantirConfiguracao();

        $resposta = $this->request()->get($this->url("v1/payments/{$paymentId}"));

        return $this->corpo($resposta);
    }

    public function cancelarPagamento(string $paymentId): void
    {
        $this->garantirConfiguracao();

        $resposta = $this->request()->put($this->url("v1/payments/{$paymentId}"), ['status' => 'cancelled']);

        $this->corpo($resposta);
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) config('integrations.mercadopago.access_token'))
            ->timeout((int) config('integrations.mercadopago.timeout', 15))
            ->acceptJson();
    }

    private function url(string $caminho): string
    {
        return self::BASE_URL.$caminho;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws IntegracaoMercadoPagoIndisponivel
     */
    private function corpo(Response $resposta): array
    {
        if ($resposta->failed()) {
            throw IntegracaoMercadoPagoIndisponivel::resposta($resposta->status(), $resposta->body());
        }

        /** @var array<string, mixed> $dados */
        $dados = $resposta->json() ?? [];

        return $dados;
    }

    /**
     * @throws IntegracaoMercadoPagoIndisponivel
     */
    private function garantirConfiguracao(): void
    {
        if (! $this->isEnabled()) {
            throw IntegracaoMercadoPagoIndisponivel::desligada();
        }

        if (blank(config('integrations.mercadopago.access_token'))) {
            throw IntegracaoMercadoPagoIndisponivel::semCredencial('access_token');
        }
    }
}
