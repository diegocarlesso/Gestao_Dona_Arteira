<?php

declare(strict_types=1);

namespace App\Modules\Finance\Contracts;

use App\Modules\Finance\DTO\CobrancaEmitida;
use App\Modules\Finance\DTO\EmitirCobrancaComando;
use App\Modules\Finance\Exceptions\CobrancaGatewayIndisponivel;

/**
 * O único ponto do sistema que fala com o provedor de cobrança —
 * ADR-0018 §Decisão. Mesmo desenho do `NfeGatewayInterface` (ADR-0009):
 * o domínio Financeiro emite um comando contra a interface; o adapter
 * concreto mora em `Integrations` e é injetado por lá — nenhum módulo
 * depende de `Integrations` (`tests/Architecture/ModulesTest.php`), então
 * só tipos e escalares (DTOs) atravessam esta fronteira, nunca um Model.
 *
 * Implementação padrão hoje: `NullCobrancaGateway` — os binds de
 * `Integrations\MercadoPago` sobrescrevem quando `integrations.mercadopago.
 * enabled` estiver ligado (mesmo espírito do `NFE_ENABLED` do Fiscal).
 */
interface CobrancaGatewayInterface
{
    /**
     * Registra a cobrança no provedor e devolve o que ele respondeu.
     *
     * **Não** existe cancelamento implícito em caso de exceção: a
     * `BillingCharge` já foi criada localmente em `pending` antes desta
     * chamada (BR-505/507) — se o provedor falhar, ela vira `failed`, não
     * desaparece. Retry é reemitir com o mesmo `chargePublicId`
     * (idempotência, ADR-0018 §3.1).
     *
     * @throws CobrancaGatewayIndisponivel gateway não configurado ou falha real do provedor
     */
    public function emitir(EmitirCobrancaComando $comando): CobrancaEmitida;

    /**
     * Cancela uma cobrança pendente no provedor — BR-506: cobrança
     * registrada é imutável, mudar é cancelar e reemitir, nunca editar.
     *
     * @throws CobrancaGatewayIndisponivel
     */
    public function cancelar(string $providerChargeId): void;
}
