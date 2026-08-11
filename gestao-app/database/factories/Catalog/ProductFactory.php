<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Enums\ProductKind;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\GenerateSku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Sem @return próprio: herda o do Factory, que o Larastan lê como
     * "chaves = propriedades reais do model" (checkModelProperties).
     */
    public function definition(): array
    {
        return [
            // Pelo gerador de verdade, não por um fake: o formato do SKU é
            // regra (BR-002), e um factory que inventasse `SKU-123`
            // deixaria os testes passarem com códigos que a aplicação
            // nunca produz.
            'sku' => app(GenerateSku::class)->proximo(),
            'name' => fake()->unique()->words(3, true),
            'kind' => ProductKind::FinishedGood,
            'unit' => 'UN',
            'status' => ProductStatus::Active,
            'height_cm' => fake()->randomFloat(2, 5, 40),
            'width_cm' => fake()->randomFloat(2, 5, 30),
            'depth_cm' => fake()->randomFloat(2, 5, 30),
            'weight_g' => fake()->randomFloat(3, 50, 3000),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductStatus::Archived,
        ]);
    }

    public function resale(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ProductKind::Resale,
        ]);
    }

    public function rawMaterial(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ProductKind::RawMaterial,
            // Peça crua não tem cor — a cor nasce na pintura (ADR-0023).
            'color' => null,
        ]);
    }

    /**
     * Com o cadastro fiscal completo — o que a NF-e exige (BR-606).
     *
     * **Não** é o default de propósito: o catálogo real veio do Woo sem
     * NCM nenhum (pasta 32), e um factory que preenchesse por padrão
     * esconderia dos testes o estado em que os 754 produtos migrados
     * realmente estão. Quem testa emissão pede explicitamente.
     *
     * NCM 6809.90.00 é a hipótese 💡 da doc 13 para obras de gesso —
     * fixture, não decisão fiscal. Origem 0 = nacional.
     */
    public function comDadosFiscais(): static
    {
        return $this->state(fn (array $attributes) => [
            'ncm' => '68099000',
            'origin' => '0',
        ]);
    }

    /** Sem peso — o caso que quebra a cotação de frete na venda. */
    public function semPeso(): static
    {
        return $this->state(fn (array $attributes) => [
            'weight_g' => null,
        ]);
    }
}
