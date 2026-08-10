<?php

declare(strict_types=1);

namespace Database\Factories\Fiscal;

use App\Modules\Fiscal\Enums\InvoiceStatus;
use App\Modules\Fiscal\Models\FiscalSeries;
use App\Modules\Fiscal\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 *
 * `order_id` **não** tem default: a coluna tem FK para `orders`, e um id
 * inventado quebraria a integridade sem dizer por quê. Quem usa a factory
 * informa o pedido — o que também mantém honesta a fronteira: nem a factory
 * do Fiscal cria pedido por conta própria.
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * Sem @return próprio: herda o do Factory, que o Larastan lê como
     * "chaves = propriedades reais do model" (checkModelProperties).
     */
    public function definition(): array
    {
        return [
            'series_id' => FiscalSeries::factory(),
            'number' => null,
            'status' => InvoiceStatus::Pending,
            'total' => '0.00',
        ];
    }

    public function autorizada(): self
    {
        return $this->state(fn (): array => [
            'status' => InvoiceStatus::Authorized,
            'number' => 1,
            'access_key' => str_repeat('1', 44),
            'protocol' => '135240000000001',
        ]);
    }

    public function rejeitada(string $motivo = 'Rejeição 778: NCM inválido.'): self
    {
        return $this->state(fn (): array => [
            'status' => InvoiceStatus::Rejected,
            'number' => 1,
            'rejection_reason' => $motivo,
        ]);
    }
}
