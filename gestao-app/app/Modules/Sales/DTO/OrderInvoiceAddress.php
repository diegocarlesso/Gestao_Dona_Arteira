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
        /**
         * Código IBGE do município (7 dígitos) — `enderDest/cMun` na NF-e.
         *
         * **Nasce nulo e por enquanto é sempre nulo:** `customer_addresses`
         * guarda cidade e UF, não o código, e ele não se deduz de cidade +
         * UF sem a tabela do IBGE. O campo existe aqui, e não só na cabeça
         * de quem escreveu a emissão, porque é assim que a lacuna fica
         * visível no contrato entre Vendas e Fiscal (ADR-0025 §2) em vez de
         * virar surpresa na primeira nota rejeitada.
         *
         * Quando a coluna existir, só esta linha do
         * `BuildOrderInvoiceSnapshot` muda.
         */
        public ?string $cityCode,
        public string $state,
    ) {}
}
