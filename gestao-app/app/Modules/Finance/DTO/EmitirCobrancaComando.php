<?php

declare(strict_types=1);

namespace App\Modules\Finance\DTO;

use App\Modules\Finance\Enums\TipoCobranca;

/**
 * O comando que o domínio Financeiro emite contra `CobrancaGatewayInterface`
 * — ADR-0018 §Decisão, item 1. Só tipos e escalares: nenhum Model do
 * Financeiro atravessa esta fronteira, porque o adapter concreto
 * (`MercadoPagoGateway`) mora em `Integrations` e não pode enxergar
 * `App\Modules\Finance\Models\*` (ADR-0020, `tests/Architecture/
 * ModulesTest.php`).
 *
 * `chargePublicId` é a chave de idempotência (`X-Idempotency-Key`) enviada
 * ao provedor — o `public_id` da `BillingCharge` já criada localmente em
 * `pending`, nunca gerado de novo a cada tentativa: reenviar o mesmo
 * comando (retry de fila) não pode criar uma segunda cobrança.
 */
final readonly class EmitirCobrancaComando
{
    public function __construct(
        public string $chargePublicId,
        public TipoCobranca $type,
        public string $amount,
        public ?string $dueDate,
        public DadosDoPagador $payer,
        public ?string $instructions = null,
    ) {}
}
