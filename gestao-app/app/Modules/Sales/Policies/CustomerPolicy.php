<?php

declare(strict_types=1);

namespace App\Modules\Sales\Policies;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Sales\Models\Customer;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Quem vê e quem mexe no cadastro de clientes (BR-801, pasta 19).
 *
 * Apoia-se nas permissões de Vendas porque cliente é cadastro de venda —
 * quem registra pedido registra cliente, e separar as duas coisas criaria
 * a situação de alguém poder vender para quem não pode cadastrar.
 *
 * **Dado pessoal (LGPD).** `sales.view` já é restrito na matriz da pasta
 * 19, e é de propósito: a lista de clientes com documento e telefone é
 * exatamente o que não deve estar visível a todo mundo que entra no
 * sistema.
 */
class CustomerPolicy
{
    public function viewAny(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::SalesView->value);
    }

    public function view(Authorizable $usuario, Customer $cliente): bool
    {
        return $usuario->can(Permission::SalesView->value);
    }

    public function create(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::SalesCreate->value);
    }

    public function update(Authorizable $usuario, Customer $cliente): bool
    {
        return $usuario->can(Permission::SalesCreate->value);
    }

    /**
     * Inativar — nunca apagar de verdade.
     *
     * O cadastro tem histórico de compra apontando para ele, e a NF-e
     * emitida guarda o destinatário por obrigação fiscal. `softDeletes`
     * some da lista sem quebrar o passado.
     */
    public function delete(Authorizable $usuario, Customer $cliente): bool
    {
        return $usuario->can(Permission::SalesCreate->value);
    }
}
