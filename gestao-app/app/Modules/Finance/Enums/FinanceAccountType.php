<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

/**
 * O tipo de uma conta financeira — docs/12-Financeiro §3.
 */
enum FinanceAccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Gateway = 'gateway';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Caixa',
            self::Bank => 'Banco',
            self::Gateway => 'Gateway de pagamento',
        };
    }
}
