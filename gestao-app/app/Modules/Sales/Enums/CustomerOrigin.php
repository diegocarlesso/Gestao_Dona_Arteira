<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

/**
 * De onde o cadastro veio.
 *
 * **Sem `legacy`.** O modelo conceitual da pasta 04 previa três origens
 * (erp/woo/legacy), mas o desktop nunca foi alimentado — correção de
 * 2026-07-25 — e nenhuma linha teria essa origem. Enum com caso que não
 * ocorre convida código a tratá-lo, e o tratamento nunca é exercitado.
 */
enum CustomerOrigin: string
{
    case Erp = 'erp';
    case Woo = 'woo';

    public function label(): string
    {
        return match ($this) {
            self::Erp => 'Cadastrado no ERP',
            self::Woo => 'Veio do site',
        };
    }
}
