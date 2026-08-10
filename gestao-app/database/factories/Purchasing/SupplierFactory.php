<?php

declare(strict_types=1);

namespace Database\Factories\Purchasing;

use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * Sem @return próprio: herda o do Factory, que o Larastan lê como
     * "chaves = propriedades reais do model" (checkModelProperties).
     */
    public function definition(): array
    {
        return [
            'legal_name' => $this->faker->company().' Ltda',
            'trade_name' => $this->faker->company(),
            // CNPJ com dígitos verificadores válidos — o cadastro real
            // passa por `DocumentoValido`, e uma factory que gerasse
            // documento inválido só testaria caminhos que a tela recusa.
            'document' => $this->cnpjValido(),
            'email' => $this->faker->companyEmail(),
            'phone' => $this->faker->numerify('(4#) 9####-####'),
            'payment_terms_days' => 30,
            'lead_time_days' => 15,
        ];
    }

    /**
     * Fornecedor sem prazo acertado — o título nasce sem vencimento.
     */
    public function semPrazoDePagamento(): static
    {
        return $this->state(fn (): array => ['payment_terms_days' => null]);
    }

    public function comPrazo(int $dias): static
    {
        return $this->state(fn (): array => ['payment_terms_days' => $dias]);
    }

    /**
     * Gera os 12 primeiros dígitos ao acaso e calcula os dois
     * verificadores — mesma conta do `App\Support\Documento`.
     */
    private function cnpjValido(): string
    {
        $base = (string) $this->faker->numerify('############');

        foreach ([[12, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]], [13, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]]] as [$posicao, $pesos]) {
            $soma = 0;

            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $base[$i] * $pesos[$i];
            }

            $resto = $soma % 11;
            $base .= (string) ($resto < 2 ? 0 : 11 - $resto);
        }

        return $base;
    }
}
