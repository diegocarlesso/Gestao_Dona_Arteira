<?php

declare(strict_types=1);

namespace Database\Seeders\Finance;

use App\Modules\Finance\Enums\FinanceAccountType;
use App\Modules\Finance\Listeners\RegistrarRecebivelAoConfirmarPedido;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Services\ProcessarNotificacaoCobrancaService;
use Illuminate\Database\Seeder;

/**
 * A conta padrão — sem ela, um pedido que nasce pago não tem onde a baixa
 * automática (`RegistrarRecebivelAoConfirmarPedido`) cair; o título fica
 * aberto e um aviso vai pro log em vez de estourar (a confirmação do
 * pedido não pode falhar por causa de cadastro financeiro incompleto).
 *
 * A conta "Mercado Pago" tem o mesmo papel para
 * `ProcessarNotificacaoCobrancaService`: sem ela, uma cobrança paga não
 * baixa o título (fica registrado em log, nunca quebra o webhook).
 *
 * Idempotente, mesmo padrão de `FinanceCategorySeeder`.
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

        FinanceAccount::query()->firstOrCreate([
            'name' => ProcessarNotificacaoCobrancaService::CONTA_MERCADO_PAGO,
        ], [
            'type' => FinanceAccountType::Gateway,
        ]);
    }
}
