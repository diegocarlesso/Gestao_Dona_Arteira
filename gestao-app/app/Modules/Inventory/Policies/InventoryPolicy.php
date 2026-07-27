<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Policies;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Inventory\Models\InventoryBalance;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Quem vê e quem mexe no estoque (BR-801, pasta 19).
 *
 * Três permissões, e a separação importa: `inventory.view` é ampla —
 * quase todo papel operacional consulta saldo —, `inventory.move` cobre o
 * movimento que nasce de um processo (expedir, produzir, receber), e
 * `inventory.adjust` cobre o ajuste de contagem, que é o único movimento
 * que nasce de alguém olhando a prateleira e dizendo que o sistema está
 * errado. É a "permissão específica" a que a BR-201 se refere.
 *
 * Ator tipado como `Authorizable` e não como o `User` do Identity, pelo
 * mesmo motivo do `ProductPolicy`: o ADR-0020 reprova o `use` do model de
 * outro módulo, e o Estoque não precisa conhecer a classe de usuário —
 * só saber que se pode perguntar a ela por permissão.
 */
class InventoryPolicy
{
    public function viewAny(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::InventoryView->value);
    }

    public function view(Authorizable $usuario, InventoryBalance $saldo): bool
    {
        return $usuario->can(Permission::InventoryView->value);
    }

    /**
     * Movimentar por processo — recebimento, produção, expedição, perda.
     */
    public function move(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::InventoryMove->value);
    }

    /**
     * Ajustar por contagem (BR-205).
     */
    public function adjust(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::InventoryAdjust->value);
    }
}
