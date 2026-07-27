<?php

declare(strict_types=1);

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\Customer;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Cliente cadastrado no ERP.
 *
 * Superfície pública do módulo (ADR-0020). Quem quiser levá-lo ao
 * WooCommerce (pasta 16) ouve isto — o Vendas não precisa conhecer a
 * integração, e a integração não precisa varrer a tabela procurando
 * novidade.
 */
class CustomerRegistered
{
    use Dispatchable;

    public function __construct(public readonly Customer $cliente) {}
}
