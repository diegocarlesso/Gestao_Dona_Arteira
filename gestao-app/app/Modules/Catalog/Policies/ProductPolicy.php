<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Policies;

use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Enums\Permission;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Quem pode ver e mexer no catálogo (BR-801, pasta 19).
 *
 * Duas permissões, não uma: `catalog.view` é ampla — todos os papéis
 * operacionais consultam o catálogo o dia inteiro —, enquanto
 * `catalog.manage` altera preço e cadastro, e é de poucos.
 *
 * O ator é tipado como `Authorizable`, o contrato do framework, e **não**
 * como o `User` do Identity: o ADR-0020 diz que a superfície pública de
 * um módulo são seus Services e Events, e o teste de arquitetura reprova
 * um `use App\Modules\Identity\Models`. Não é contorno da regra — é a
 * regra apontando o acoplamento certo: o catálogo não precisa conhecer a
 * classe de usuário, só saber que se pode perguntar a ela por permissão.
 */
class ProductPolicy
{
    public function viewAny(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::CatalogView->value);
    }

    public function view(Authorizable $usuario, Product $produto): bool
    {
        return $usuario->can(Permission::CatalogView->value);
    }

    /**
     * Ver os relatórios do catálogo (pasta 20).
     *
     * `reports.view`, e não `catalog.view`: relatório é uma família de
     * permissões própria na matriz da pasta 19, porque quem consulta uma
     * ficha de produto no dia a dia não é necessariamente quem recebe uma
     * lista consolidada do catálogo inteiro — e o contrário também vale,
     * como no perfil do contador.
     */
    public function viewReports(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::ReportsView->value);
    }

    public function create(Authorizable $usuario): bool
    {
        return $usuario->can(Permission::CatalogManage->value);
    }

    public function update(Authorizable $usuario, Product $produto): bool
    {
        return $usuario->can(Permission::CatalogManage->value);
    }

    /**
     * Definir preço.
     *
     * Habilidade própria, separada de `update`, porque preço é a alteração
     * de catálogo com efeito financeiro imediato — e um dia pode querer
     * alçada própria (como o desconto em BR-303). Hoje ambas caem em
     * `catalog.manage`; a separação deixa o caminho aberto sem custo.
     */
    public function setPrice(Authorizable $usuario, Product $produto): bool
    {
        return $usuario->can(Permission::CatalogManage->value);
    }

    public function archive(Authorizable $usuario, Product $produto): bool
    {
        return $usuario->can(Permission::CatalogManage->value);
    }

    /**
     * Ninguém exclui produto (BR-008) — nem o admin.
     *
     * Devolve `false` sempre, e não é redundante com "não existe rota de
     * exclusão": o `Gate::before` do Identity concede tudo ao admin, então
     * sem esta negativa explícita um `$user->can('delete', $produto)` em
     * código futuro responderia `true` e alguém construiria a tela.
     */
    public function delete(Authorizable $usuario, Product $produto): bool
    {
        return false;
    }
}
