<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Policies;

use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Identity\Enums\Permission;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Quem vê e reemite notas fiscais (BR-801, pasta 19).
 *
 * `fiscal.view` é bem mais distribuída que `fiscal.emit` (pasta 19 §3): a
 * matriz dá `fiscal.view` a Vendas, Expedição e Financeiro, porque todos
 * esbarram na pendência (BR-309 barra a expedição, o financeiro cobra sem
 * nota). Reemitir é ato de quem resolve — mesma alçada de emitir.
 */
class InvoicePolicy
{
    public function viewAny(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::FiscalView->value);
    }

    public function view(Authorizable $usuario, Invoice $nota): bool
    {
        return $usuario->can(Permission::FiscalView->value);
    }

    public function reprocess(Authorizable $usuario, Invoice $nota): bool
    {
        return $usuario->can(Permission::FiscalEmit->value);
    }
}
