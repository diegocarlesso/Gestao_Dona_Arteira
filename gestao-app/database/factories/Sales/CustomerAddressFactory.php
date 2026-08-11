<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;
use App\Modules\Sales\Models\IbgeMunicipality;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAddress>
 */
class CustomerAddressFactory extends Factory
{
    protected $model = CustomerAddress::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'label' => 'Casa',
            'zip' => fake()->numerify('########'),
            'street' => fake()->streetName(),
            'number' => (string) fake()->buildingNumber(),
            'district' => fake()->citySuffix(),
            'city' => fake()->city(),
            // RS concentra 61% dos pedidos do legado.
            'state' => 'RS',
            // `city_code` fica de fora do padrão de propósito (ADR-0026):
            // endereço nasce sem município resolvido, e é isso que a suíte
            // precisa poder exercitar. Preenchê-lo aqui também obrigaria
            // **todo** teste que cria endereço a ter a tabela de municípios
            // semeada, por causa da FK — `RefreshDatabase` não roda seeder.
            // Quem precisa do código pede: `->comMunicipio()`.
            'is_default_shipping' => true,
            'is_default_billing' => true,
        ];
    }

    /**
     * Endereço com o município do IBGE já resolvido — o estado de quem
     * pode virar NF-e.
     *
     * Semeia a linha de referência que a FK exige (`firstOrCreate`, então
     * convive com o seeder completo se o teste já o tiver rodado) e alinha
     * cidade e UF ao município escolhido: um endereço em "Porto Alegre/RS"
     * com o código de outra cidade seria um dado que o sistema real nunca
     * produz, e testar sobre ele testa ficção.
     *
     * O padrão é Porto Alegre/RS (`4314902`) — código público, conferível,
     * e na UF que a loja mais atende.
     */
    public function comMunicipio(string $codigo = '4314902', string $uf = 'RS', string $nome = 'Porto Alegre'): static
    {
        return $this->state(function () use ($codigo, $uf, $nome): array {
            IbgeMunicipality::query()->firstOrCreate(
                ['ibge_code' => $codigo],
                ['uf' => $uf, 'name' => $nome],
            );

            return ['city' => $nome, 'state' => $uf, 'city_code' => $codigo];
        });
    }
}
