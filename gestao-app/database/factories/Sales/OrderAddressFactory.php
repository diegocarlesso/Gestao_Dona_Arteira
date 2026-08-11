<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Modules\Sales\Models\IbgeMunicipality;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderAddress>
 */
class OrderAddressFactory extends Factory
{
    protected $model = OrderAddress::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'type' => 'shipping',
            'zip' => fake()->numerify('########'),
            'street' => fake()->streetName(),
            'number' => (string) fake()->buildingNumber(),
            'district' => fake()->citySuffix(),
            'city' => fake()->city(),
            // RS concentra 61% dos pedidos do legado.
            'state' => 'RS',
            // `city_code` fica de fora do padrão de propósito (ADR-0026):
            // endereço nasce sem município resolvido, e é isso que a suíte
            // precisa poder exercitar. Quem precisa do código pede:
            // `->comMunicipio()`.
        ];
    }

    /**
     * Endereço com o município do IBGE já resolvido — o estado de quem
     * pode virar NF-e.
     *
     * Semeia a linha de referência que a FK exige (`firstOrCreate`, então
     * convive com o seeder completo se o teste já o tiver rodado) e alinha
     * cidade e UF ao município escolhido — mesmo padrão de
     * `CustomerAddressFactory::comMunicipio()`.
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

    public function billing(): static
    {
        return $this->state(['type' => 'billing']);
    }

    public function shipping(): static
    {
        return $this->state(['type' => 'shipping']);
    }
}
