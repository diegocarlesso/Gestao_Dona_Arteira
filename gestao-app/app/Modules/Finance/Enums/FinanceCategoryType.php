<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

/**
 * De que lado do caixa a categoria vive — BR-503.
 *
 * O tipo não é enfeite de relatório: é o que impede um título a pagar de
 * ser classificado como receita. O fluxo de caixa soma entrada e saída em
 * colunas diferentes, e é este enum que decide qual é qual.
 */
enum FinanceCategoryType: string
{
    case Payable = 'payable';
    case Receivable = 'receivable';

    public function label(): string
    {
        return match ($this) {
            self::Payable => 'Contas a pagar',
            self::Receivable => 'Contas a receber',
        };
    }
}
