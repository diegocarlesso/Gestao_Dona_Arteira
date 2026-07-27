<?php

declare(strict_types=1);

namespace Database\Seeders\Inventory;

use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use Illuminate\Database\Seeder;

/**
 * O ateliê — o único local que existe hoje.
 *
 * Idempotente (regra 5 da pasta 04): roda em todo deploy. Casa por
 * `name`, e **não** mexe no que já estiver lá: se alguém renomear o local
 * ou marcar outro como padrão, o seeder não deve desfazer a decisão de
 * quem opera. Ele garante que exista um local, não que exista este.
 */
class LocalPadraoSeeder extends Seeder
{
    public function run(): void
    {
        if (Location::query()->exists()) {
            return;
        }

        Location::query()->create([
            'name' => 'Ateliê',
            'type' => LocationType::Atelier,
            'is_default' => true,
            'is_active' => true,
        ]);
    }
}
