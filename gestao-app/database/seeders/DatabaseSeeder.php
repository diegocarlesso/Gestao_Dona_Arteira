<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Finance\FinanceAccountSeeder;
use Database\Seeders\Finance\FinanceCategorySeeder;
use Database\Seeders\Identity\AdminInicialSeeder;
use Database\Seeders\Identity\RolePermissionSeeder;
use Database\Seeders\Inventory\LocalPadraoSeeder;
use Database\Seeders\Sales\IbgeMunicipalitySeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds de referência do ERP.
     *
     * Todos idempotentes (regra 5 da pasta 04): este comando roda em
     * todo deploy, não apenas na instalação. Um seeder que duplica dados
     * ao ser reexecutado transforma o deploy em operação manual.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            AdminInicialSeeder::class,
            LocalPadraoSeeder::class,
            FinanceCategorySeeder::class,
            FinanceAccountSeeder::class,
            IbgeMunicipalitySeeder::class,
        ]);
    }
}
