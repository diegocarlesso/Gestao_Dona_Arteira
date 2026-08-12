<?php

declare(strict_types=1);

namespace App\Modules\Integrations\MercadoPago\Adapters;

use App\Modules\Finance\Contracts\CobrancaGatewayInterface;
use App\Modules\Finance\DTO\CobrancaEmitida;
use App\Modules\Finance\DTO\EmitirCobrancaComando;
use App\Modules\Finance\Enums\TipoCobranca;
use App\Modules\Finance\Exceptions\CobrancaGatewayIndisponivel;
use App\Modules\Integrations\MercadoPago\Client;
use App\Modules\Integrations\MercadoPago\Exceptions\IntegracaoMercadoPagoIndisponivel;
use App\Modules\Integrations\MercadoPago\Mappers\StatusMap;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Implementação real de `CobrancaGatewayInterface` — ADR-0018 §Decisão.
 *
 * Mora em `Integrations` (não em `Finance`, diferente do `SpedNfeGateway`
 * do Fiscal) porque o próprio ADR-0018 atribui o adapter concreto à camada
 * de integrações, sob o framework da pasta 15. Só tipos/DTOs do Financeiro
 * atravessam a fronteira (`tests/Architecture/ModulesTest.php` proíbe
 * `Integrations` de enxergar `Finance\Models\*`).
 *
 * Toda exceção do `Client`/rede vira `CobrancaGatewayIndisponivel` antes de
 * sair daqui — quem chama (`Finance\Services\EmitirCobrancaService`) só
 * conhece esse tipo, nunca o vocabulário interno do Mercado Pago.
 */
class MercadoPagoGateway implements CobrancaGatewayInterface
{
    public function __construct(
        private readonly Client $client,
    ) {}

    public function emitir(EmitirCobrancaComando $comando): CobrancaEmitida
    {
        $payload = $this->montarPayload($comando);

        try {
            $resposta = $this->client->criarPagamento($payload, $comando->chargePublicId);
        } catch (IntegracaoMercadoPagoIndisponivel $e) {
            throw CobrancaGatewayIndisponivel::falhaDoProvedor($e->getMessage());
        } catch (Throwable $e) {
            throw CobrancaGatewayIndisponivel::falhaDoProvedor(
                'Falha de comunicação com o Mercado Pago: '.$e->getMessage()
            );
        }

        return $this->paraCobrancaEmitida($resposta);
    }

    public function cancelar(string $providerChargeId): void
    {
        try {
            $this->client->cancelarPagamento($providerChargeId);
        } catch (IntegracaoMercadoPagoIndisponivel $e) {
            throw CobrancaGatewayIndisponivel::falhaDoProvedor($e->getMessage());
        } catch (Throwable $e) {
            throw CobrancaGatewayIndisponivel::falhaDoProvedor(
                'Falha de comunicação com o Mercado Pago: '.$e->getMessage()
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function montarPayload(EmitirCobrancaComando $comando): array
    {
        [$primeiroNome, $sobrenome] = $this->separarNome($comando->payer->name);

        $payload = [
            // O Mercado Pago exige número no corpo JSON, não string — o
            // valor já chegou como decimal correto (ADR-0013) até aqui;
            // este `(float)` é só a serialização de saída para um
            // contrato de terceiro, nenhuma aritmética é feita com ele.
            'transaction_amount' => (float) $comando->amount,
            'payment_method_id' => $comando->type === TipoCobranca::Pix ? 'pix' : 'bolbradesco',
            'description' => $comando->instructions,
            'payer' => [
                'email' => $comando->payer->email,
                'first_name' => $primeiroNome,
                'last_name' => $sobrenome,
                'identification' => [
                    'type' => $comando->payer->documentType,
                    'number' => $comando->payer->documentNumber,
                ],
            ],
        ];

        if ($comando->dueDate !== null) {
            $payload['date_of_expiration'] = Carbon::parse($comando->dueDate, 'America/Sao_Paulo')
                ->endOfDay()
                ->toIso8601String();
        }

        if ($comando->type === TipoCobranca::Boleto) {
            $payload['payer']['address'] = [
                'zip_code' => $comando->payer->addressZip,
                'street_name' => $comando->payer->addressStreet,
                'street_number' => $comando->payer->addressNumber,
                'neighborhood' => $comando->payer->addressNeighborhood,
                'city' => $comando->payer->addressCity,
                'state' => $comando->payer->addressState,
            ];
        }

        return array_filter($payload, static fn (mixed $valor): bool => $valor !== null);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function separarNome(string $nomeCompleto): array
    {
        $partes = explode(' ', trim($nomeCompleto), 2);

        return [$partes[0], $partes[1] ?? $partes[0]];
    }

    /**
     * @param  array<string, mixed>  $resposta
     */
    private function paraCobrancaEmitida(array $resposta): CobrancaEmitida
    {
        $statusBruto = (string) ($resposta['status'] ?? 'pending');

        return new CobrancaEmitida(
            providerChargeId: (string) ($resposta['id'] ?? ''),
            status: StatusMap::de($statusBruto),
            digitableLine: data_get($resposta, 'transaction_details.digitable_line'),
            barcode: data_get($resposta, 'transaction_details.barcode') ?? data_get($resposta, 'barcode.content'),
            pixPayload: data_get($resposta, 'point_of_interaction.transaction_data.qr_code'),
            pdfUrl: data_get($resposta, 'transaction_details.external_resource_url'),
            rawPayload: $resposta,
        );
    }
}
