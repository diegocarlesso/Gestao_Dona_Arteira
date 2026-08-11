<?php

declare(strict_types=1);

namespace App\Modules\Sales\DTO;

/**
 * Um endereço do cliente, como o ERP o guarda hoje — para a validação da
 * migração (F5).
 *
 * Separado do `OrderInvoiceAddress`: aquele é o endereço **congelado** no
 * pedido para virar destinatário de NF-e; este é o cadastro vivo, que a
 * conferência compara com a origem. Reaproveitar um pelo outro misturaria
 * o dado que muda com o dado que não pode mudar.
 */
final readonly class CustomerAddressSnapshot
{
    public function __construct(
        public string $zip,
        public string $street,
        public ?string $number,
        public ?string $complement,
        public ?string $district,
        public string $city,
        public string $state,
        public bool $isDefaultBilling,
        public bool $isDefaultShipping,
    ) {}
}
