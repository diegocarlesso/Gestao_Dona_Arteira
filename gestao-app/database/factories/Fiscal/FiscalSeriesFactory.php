<?php

declare(strict_types=1);

namespace Database\Factories\Fiscal;

use App\Modules\Fiscal\Models\FiscalSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalSeries>
 */
class FiscalSeriesFactory extends Factory
{
    protected $model = FiscalSeries::class;

    /**
     * Sem @return próprio: herda o do Factory, que o Larastan lê como
     * "chaves = propriedades reais do model" (checkModelProperties).
     */
    public function definition(): array
    {
        return [
            // Casa com o default de `config('fiscal.nfe.series')`, para o
            // teste não precisar configurar as duas pontas.
            'series' => '1',
            'next_number' => 1,
            'environment' => 'homologacao',
        ];
    }

    public function emProducao(): self
    {
        return $this->state(fn (): array => ['environment' => 'producao']);
    }

    public function apartirDe(int $numero): self
    {
        return $this->state(fn (): array => ['next_number' => $numero]);
    }
}
