<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Reserva **crua**, sem passar pelo serviço — para testar o model e os
 * escopos. Teste de regra usa o `ReserveStockService`, que é quem valida
 * o disponível e mexe no saldo; uma reserva que o contorne provaria algo
 * que a aplicação não faz.
 *
 * @extends Factory<StockReservation>
 */
class StockReservationFactory extends Factory
{
    protected $model = StockReservation::class;

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'qty' => '1.000',
            'status' => ReservationStatus::Active,
        ];
    }
}
