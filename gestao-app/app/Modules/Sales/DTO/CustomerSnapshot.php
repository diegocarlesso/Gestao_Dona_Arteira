<?php

declare(strict_types=1);

namespace App\Modules\Sales\DTO;

use App\Modules\Sales\Enums\CustomerOrigin;
use App\Modules\Sales\Enums\CustomerType;

/**
 * O estado atual de um cliente, para a validação da migração (F5).
 *
 * Existe para que a conferência (no módulo Integrations) compare a carga
 * com a origem **sem** ler `Sales\Models` nem a tabela `customers`
 * (ADR-0020) — mesmo papel do `ProductSnapshot` no Catálogo.
 *
 * Carrega os endereços junto porque é justamente o que a migração de
 * clientes traz de novo: o `ResolveCustomerService`, que cria o cliente
 * quando um pedido do site chega, nunca grava endereço. Uma conferência
 * que não olhasse os endereços daria verde sem olhar a única coisa que
 * esta carga acrescenta.
 */
final readonly class CustomerSnapshot
{
    /**
     * @param  list<CustomerAddressSnapshot>  $enderecos
     */
    public function __construct(
        public string $name,
        public ?string $email,
        public ?string $doc,
        public ?string $phone,
        public CustomerType $type,
        public CustomerOrigin $origin,
        public bool $isWholesale,
        public array $enderecos,
    ) {}

    public function enderecoDeCobranca(): ?CustomerAddressSnapshot
    {
        foreach ($this->enderecos as $endereco) {
            if ($endereco->isDefaultBilling) {
                return $endereco;
            }
        }

        return null;
    }

    public function enderecoDeEntrega(): ?CustomerAddressSnapshot
    {
        foreach ($this->enderecos as $endereco) {
            if ($endereco->isDefaultShipping) {
                return $endereco;
            }
        }

        return null;
    }
}
