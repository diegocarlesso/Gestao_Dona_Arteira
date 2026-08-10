<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Policies;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Quem vê e quem mexe no cadastro de fornecedores (BR-801, pasta 19).
 *
 * Uma permissão só (`purchasing.manage`) para ver e para escrever: a matriz
 * da pasta 19 dá Compras inteiro a quem administra, e inventar aqui um
 * `purchasing.view` que não existe no enum não daria acesso a ninguém
 * (BR-801 nega por padrão) — daria só a impressão de granularidade.
 */
class SupplierPolicy
{
    public function viewAny(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::PurchasingManage->value);
    }

    public function view(Authorizable $usuario, Supplier $fornecedor): bool
    {
        return $usuario->can(Permission::PurchasingManage->value);
    }

    public function create(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::PurchasingManage->value);
    }

    public function update(Authorizable $usuario, Supplier $fornecedor): bool
    {
        return $usuario->can(Permission::PurchasingManage->value);
    }

    /**
     * Inativar — nunca apagar de verdade.
     *
     * O fornecedor tem pedidos de compra apontando para ele e o título a
     * pagar guarda o nome dele por snapshot; `softDeletes` o tira da lista
     * sem quebrar o passado, como no cadastro de clientes.
     */
    public function delete(Authorizable $usuario, Supplier $fornecedor): bool
    {
        return $usuario->can(Permission::PurchasingManage->value);
    }
}
