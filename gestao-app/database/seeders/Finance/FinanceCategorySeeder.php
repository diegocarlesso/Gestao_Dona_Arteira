<?php

declare(strict_types=1);

namespace Database\Seeders\Finance;

use App\Modules\Finance\Enums\FinanceCategoryType;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Services\RegisterPayableService;
use Illuminate\Database\Seeder;

/**
 * A categoria em que caem as compras de fornecedor — BR-503.
 *
 * O mínimo para que nenhum título nasça sem categoria. O plano gerencial
 * completo (docs/12 §4) é Gate 04, e será cadastrado, não semeado.
 *
 * Idempotente (regra 5 da pasta 04): roda em todo deploy. Usa
 * `firstOrCreate` e **não** mexe no que já existe — se alguém pendurar
 * esta categoria sob um pai ("Custos") no Gate 04, o seeder não desfaz a
 * decisão. Ele garante que a categoria exista, não que ela esteja na raiz.
 */
class FinanceCategorySeeder extends Seeder
{
    public function run(): void
    {
        FinanceCategory::query()->firstOrCreate([
            'name' => RegisterPayableService::CATEGORIA_PADRAO_COMPRAS,
            'type' => FinanceCategoryType::Payable,
        ]);
    }
}
