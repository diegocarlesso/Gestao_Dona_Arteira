<?php

declare(strict_types=1);

namespace App\Modules\Finance\DTO;

use App\Modules\Finance\Enums\StatusCobranca;

/**
 * O que o provedor devolveu ao registrar uma cobrança — resposta de
 * `CobrancaGatewayInterface::emitir()`. `rawPayload` é guardado no
 * `billing_charge_events` para depuração, nunca interpretado pelo domínio.
 */
final readonly class CobrancaEmitida
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $providerChargeId,
        public StatusCobranca $status,
        public ?string $digitableLine,
        public ?string $barcode,
        public ?string $pixPayload,
        public ?string $pdfUrl,
        public array $rawPayload,
    ) {}
}
