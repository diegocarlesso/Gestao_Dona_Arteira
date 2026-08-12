<?php

declare(strict_types=1);

namespace App\Modules\Sales\Policies;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Sales\Models\Order;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Quem vê, cria e cancela pedidos (BR-801, pasta 19).
 *
 * `sales.cancel` é separada de `sales.create` de propósito: cancelar um
 * pedido confirmado libera estoque reservado e pode ter efeito fiscal
 * adiante — não é a mesma alçada de rascunhar uma venda.
 */
class OrderPolicy
{
    public function viewAny(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::SalesView->value);
    }

    public function view(Authorizable $usuario, Order $pedido): bool
    {
        return $usuario->can(Permission::SalesView->value);
    }

    public function create(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::SalesCreate->value);
    }

    public function update(Authorizable $usuario, Order $pedido): bool
    {
        return $usuario->can(Permission::SalesCreate->value);
    }

    /**
     * Confirmar — reserva estoque. Mesma alçada de criar.
     */
    public function confirm(Authorizable $usuario, Order $pedido): bool
    {
        return $usuario->can(Permission::SalesCreate->value);
    }

    public function cancel(Authorizable $usuario, Order $pedido): bool
    {
        return $usuario->can(Permission::SalesCancel->value);
    }

    /**
     * Excluir (BR-311) — mesma alçada de cancelar, é a mesma trilha de
     * confiança que já mexe no estado final do pedido.
     */
    public function delete(Authorizable $usuario, Order $pedido): bool
    {
        return $usuario->can(Permission::SalesCancel->value);
    }

    /**
     * Separar, embalar, expedir e entregar — alçada da Expedição
     * (`fulfillment.execute`), distinta de criar/cancelar venda. É a mão que
     * mexe na peça, não a que fecha o negócio.
     */
    public function fulfill(Authorizable $usuario, Order $pedido): bool
    {
        return $usuario->can(Permission::FulfillmentExecute->value);
    }

    /**
     * `reports.view`, e não `sales.view`: relatório é família de
     * permissão própria (pasta 20) — quem lança pedido não
     * necessariamente vê o consolidado por período/canal.
     */
    public function viewReports(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::ReportsView->value);
    }
}
