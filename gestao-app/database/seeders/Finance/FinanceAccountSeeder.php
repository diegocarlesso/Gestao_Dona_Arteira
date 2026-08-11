<?php

declare(strict_types=1);

namespace Database\Seeders\Finance;

use App\Modules\Finance\Enums\FinanceAccountType;
use App\Modules\Finance\Listeners\RegistrarRecebivelAoConfirmarPedido;
use App\Modules\Finance\Models\FinanceAccount;
use Illuminate\Database\Seeder;

/**
 * A conta padrão — sem ela, um pedido que nasce pago não tem onde a baixa
 * automática (`RegistrarRecebivelAoConfirmarPedido`) cair; o título fica
 * aberto e um aviso vai pro log em vez de estourar (a confirmação do
 * pedido não pode falhar por causa de cadastro financeiro incompleto).
 *
 * Idempotente, mesmo padrão de `FinanceCategorySeeder`. A separação por
 * canal (dinheiro do balcão vs. gateway do site) é a Fase C (cobrança
 * Mercado Pago) — por ora, uma conta só.
 */
class FinanceAccountSeeder extends Seeder
{
    public function run(): void
    {
        FinanceAccount::query()->firstOrCreate([
            'name' => RegistrarRecebivelAoConfirmarPedido::CONTA_PADRAO,
        ], [
            'type' => FinanceAccountType::Cash,
        ]);
    }
}
