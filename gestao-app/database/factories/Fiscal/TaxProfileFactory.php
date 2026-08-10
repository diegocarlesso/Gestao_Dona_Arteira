<?php

declare(strict_types=1);

namespace Database\Factories\Fiscal;

use App\Modules\Fiscal\Models\TaxProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<TaxProfile>
 *
 * Os valores default são as **hipóteses 💡 da doc 13** (CFOP 5101, CSOSN
 * 102), e vivem aqui — na factory, que só o teste usa — e não num seeder,
 * de propósito: um seeder as colocaria no banco de produção com cara de
 * decisão tomada. Enquanto o contador não validar, elas são fixture de
 * teste, nada mais.
 */
class TaxProfileFactory extends Factory
{
    protected $model = TaxProfile::class;

    /**
     * Sem @return próprio: herda o do Factory, que o Larastan lê como
     * "chaves = propriedades reais do model" (checkModelProperties).
     */
    public function definition(): array
    {
        return [
            'operation_type' => TaxProfile::OPERACAO_VENDA,
            'destination' => TaxProfile::DESTINO_DENTRO_UF,
            'customer_type' => TaxProfile::CLIENTE_PF,
            'cfop' => '5101',
            'csosn' => '102',
            'notes' => 'Documento emitido por ME ou EPP optante pelo Simples Nacional.',
            'valid_from' => Carbon::now()->subYear()->toDateString(),
            'valid_to' => null,
        ];
    }

    public function foraDaUf(): self
    {
        return $this->state(fn (): array => [
            'destination' => TaxProfile::DESTINO_FORA_UF,
            'cfop' => '6101',
        ]);
    }

    public function paraPessoaJuridica(): self
    {
        return $this->state(fn (): array => ['customer_type' => TaxProfile::CLIENTE_PJ]);
    }

    /** Perfil já expirado — para testar que a vigência é respeitada. */
    public function expirado(): self
    {
        return $this->state(fn (): array => [
            'valid_from' => Carbon::now()->subYears(3)->toDateString(),
            'valid_to' => Carbon::now()->subYear()->toDateString(),
        ]);
    }
}
