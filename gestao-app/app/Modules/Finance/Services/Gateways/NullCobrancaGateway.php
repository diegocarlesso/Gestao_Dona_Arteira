<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services\Gateways;

use App\Modules\Finance\Contracts\CobrancaGatewayInterface;
use App\Modules\Finance\DTO\CobrancaEmitida;
use App\Modules\Finance\DTO\EmitirCobrancaComando;
use App\Modules\Finance\Exceptions\CobrancaGatewayIndisponivel;
use Illuminate\Support\Facades\Log;

/**
 * O gateway que **não emite cobrança nenhuma** — e diz isso em voz alta.
 *
 * Implementação ativa enquanto o Access Token de produção do Mercado Pago
 * não estiver configurado (`integrations.mercadopago.enabled`). Mesmo
 * raciocínio do `NullNfeGateway` (Fiscal/ADR-0025): fingir sucesso deixaria
 * o operador achando que mandou um boleto real para o cliente quando não
 * mandou nada — o modo de falha exato que a mensagem clara existe para
 * evitar. Diferente do Fiscal, aqui **não** existe um estado "pendente"
 * aceitável: uma cobrança que o operador pediu e não foi emitida é uma
 * falha visível (`EmitirCobrancaService` marca `status=failed`), não um
 * documento "ainda a caminho".
 *
 * Trocar por `MercadoPagoGateway` é o rebind que `Integrations\MercadoPago\
 * Providers\MercadoPagoServiceProvider` faz quando a integração está ligada.
 */
class NullCobrancaGateway implements CobrancaGatewayInterface
{
    public const MOTIVO = 'Cobrança automática ainda não configurada — falta o Access Token de '
        .'produção do Mercado Pago (integrations.mercadopago.enabled). Emita manualmente pelo '
        .'painel do Mercado Pago ou pelo internet banking (ADR-0018, Alternativa D) enquanto isso.';

    public function emitir(EmitirCobrancaComando $comando): CobrancaEmitida
    {
        Log::warning('Financeiro: emissão de cobrança recusada, gateway real ainda não integrado (ADR-0018).', [
            'charge_public_id' => $comando->chargePublicId,
            'type' => $comando->type->value,
            'amount' => $comando->amount,
        ]);

        throw CobrancaGatewayIndisponivel::naoConfigurado(self::MOTIVO);
    }

    public function cancelar(string $providerChargeId): void
    {
        Log::warning('Financeiro: cancelamento de cobrança recusado, gateway real ainda não integrado (ADR-0018).', [
            'provider_charge_id' => $providerChargeId,
        ]);

        throw CobrancaGatewayIndisponivel::naoConfigurado(self::MOTIVO);
    }
}
