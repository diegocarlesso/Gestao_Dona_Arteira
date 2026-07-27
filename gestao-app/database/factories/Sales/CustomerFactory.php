<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Modules\Sales\Enums\CustomerOrigin;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'type' => CustomerType::Individual,
            'name' => fake()->name(),
            // Sem documento por padrão: é o cliente de balcão, que a
            // BR-001 permite existir sem CPF, e o caso mais comum na
            // operação da loja.
            'doc' => null,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('(51) 9####-####'),
            'is_wholesale' => false,
            'origin' => CustomerOrigin::Erp,
        ];
    }

    /**
     * Com CPF válido de verdade — calculado, não sorteado, porque a
     * BR-001 valida dígito verificador e um número inventado reprovaria.
     */
    public function comCpf(): static
    {
        return $this->state(fn (array $attributes) => ['doc' => self::cpfValido()]);
    }

    public function atacadista(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CustomerType::Company,
            'is_wholesale' => true,
        ]);
    }

    public function doSite(): static
    {
        return $this->state(fn (array $attributes) => ['origin' => CustomerOrigin::Woo]);
    }

    private static function cpfValido(): string
    {
        $base = '';

        for ($i = 0; $i < 9; $i++) {
            $base .= random_int(0, 9);
        }

        foreach ([9, 10] as $posicao) {
            $soma = 0;

            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $base[$i] * ($posicao + 1 - $i);
            }

            $resto = ($soma * 10) % 11;
            $base .= $resto === 10 ? 0 : $resto;
        }

        return $base;
    }
}
