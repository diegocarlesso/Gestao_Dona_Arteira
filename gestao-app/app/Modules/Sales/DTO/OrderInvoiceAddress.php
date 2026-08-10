<?php

declare(strict_types=1);

namespace App\Modules\Sales\DTO;

/**
 * O endereço do pedido, achatado para quem vai faturar.
 *
 * Não é o `CustomerAddress`: é a cópia dos campos que a NF-e declara, sem
 * o model, sem os defaults de entrega/cobrança e sem os hooks de
 * normalização — quem recebe isto não deve poder salvar nada.
 */
final readonly class OrderInvoiceAddress
{
    public function __construct(
        public string $zip,
        public string $street,
        public ?string $number,
        public ?string $complement,
        public ?string $district,
        public string $city,
        public string $state,
    ) {}
}
