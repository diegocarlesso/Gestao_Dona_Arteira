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
         * Vem de `customer_addresses.city_code`, resolvido a partir de
         * cidade + UF contra a tabela `ibge_municipalities` (ADR-0026).
         *
         * **Continua nulável**, e isso é o desenho, não uma lacuna: quando
         * a grafia do cadastro não bate com a oficial do IBGE, a resolução
         * recusa aproximar e o endereço fica pendente até alguém escolher o
         * município na tela do cliente. Nulo aqui significa "este endereço
         * ainda não pode virar nota", e quem para a emissão com mensagem
         * própria é o Fiscal — nunca um código chutado.
         */
        public ?string $cityCode,
        public string $state,
    ) {}
}
