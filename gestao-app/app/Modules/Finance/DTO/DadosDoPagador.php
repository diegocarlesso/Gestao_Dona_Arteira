<?php

declare(strict_types=1);

namespace App\Modules\Finance\DTO;

/**
 * O que o Mercado Pago exige do pagador para registrar uma cobrança —
 * ADR-0018 §3.1. Snapshot fornecido pelo operador ao emitir, não uma
 * referência ao cadastro de cliente (o Financeiro não tem esse cadastro —
 * `Receivable` guarda só `customer_name`, ADR-0020).
 *
 * Campos de endereço são nulos por padrão: só PIX sem endereço é aceito
 * pelo provedor; boleto exige todos (`TipoCobranca::exigeEnderecoDoPagador`)
 * — quem valida isso é o Service, não o DTO.
 */
final readonly class DadosDoPagador
{
    public function __construct(
        public string $name,
        public string $email,
        public string $documentType,
        public string $documentNumber,
        public ?string $addressZip = null,
        public ?string $addressStreet = null,
        public ?string $addressNumber = null,
        public ?string $addressNeighborhood = null,
        public ?string $addressCity = null,
        public ?string $addressState = null,
    ) {}

    public function temEnderecoCompleto(): bool
    {
        return $this->addressZip !== null
            && $this->addressStreet !== null
            && $this->addressNumber !== null
            && $this->addressNeighborhood !== null
            && $this->addressCity !== null
            && $this->addressState !== null;
    }
}
