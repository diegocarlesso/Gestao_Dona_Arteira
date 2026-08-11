<?php

declare(strict_types=1);

namespace Database\Seeders\Finance;

use App\Modules\Finance\Enums\FinanceCategoryType;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Services\RegisterPayableService;
use App\Modules\Finance\Services\RegisterReceivableService;
use Illuminate\Database\Seeder;

/**
 * O plano de categorias gerencial — BR-503, docs/12-Financeiro §4.
 *
 * Diferente de `tax_profiles` (que nasce vazio de propósito, porque CFOP
 * errado é autuação): categoria errada é `rename` numa tela, sem risco
 * fiscal nenhum. Por isso semeia a árvore **proposta** na doc como default
 * editável — não trava esperando o dono validar, mas também não finge que
 * a validação já aconteceu.
 *
 * Idempotente (`firstOrCreate`), roda em todo deploy — não mexe em quem
 * já existe, então renomear ou reparentar pela tela é seguro.
 */
class FinanceCategorySeeder extends Seeder
{
    public function run(): void
    {
        FinanceCategory::query()->firstOrCreate([
            'name' => RegisterPayableService::CATEGORIA_PADRAO_COMPRAS,
            'type' => FinanceCategoryType::Payable,
        ]);

        FinanceCategory::query()->firstOrCreate([
            'name' => RegisterReceivableService::CATEGORIA_PADRAO_VENDAS,
            'type' => FinanceCategoryType::Receivable,
        ]);

        $this->arvore(FinanceCategoryType::Receivable, 'Receitas', [
            'Vendas Site', 'Vendas Balcão', 'Vendas Atacado', 'Fretes cobrados',
        ]);

        $this->arvore(FinanceCategoryType::Payable, 'Custos', [
            'Matéria-prima', 'Embalagens', 'Frete pago',
        ]);

        $this->arvore(FinanceCategoryType::Payable, 'Despesas', [
            'Marketing', 'Taxas de gateway/marketplace', 'Hospedagem/Software',
            'Pró-labore', 'Energia/Água', 'Outros',
        ]);

        $this->arvore(FinanceCategoryType::Payable, 'Investimentos', [
            'Equipamentos', 'Ateliê/bancada',
        ]);
    }

    /**
     * @param  list<string>  $filhos
     */
    private function arvore(FinanceCategoryType $tipo, string $raiz, array $filhos): void
    {
        $pai = FinanceCategory::query()->firstOrCreate([
            'name' => $raiz,
            'type' => $tipo,
        ]);

        foreach ($filhos as $filho) {
            FinanceCategory::query()->firstOrCreate([
                'name' => $filho,
                'type' => $tipo,
                'parent_id' => $pai->id,
            ]);
        }
    }
}
