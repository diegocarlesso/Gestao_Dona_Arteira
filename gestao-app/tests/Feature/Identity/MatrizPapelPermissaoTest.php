<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\Identity\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Matriz papel × permissão — docs/19-Permissoes/README.md §3
|--------------------------------------------------------------------------
|
| A tabela abaixo é a transcrição da matriz publicada. Ela existe
| SEPARADA do enum Role de propósito: se o teste lesse as permissões do
| próprio enum, provaria apenas que o enum é igual a si mesmo.
|
| Mudar acesso passa a exigir três edições coerentes — o documento, o
| enum e esta tabela. É deliberadamente chato: BR-801 diz que acesso é
| negado por padrão, e ampliar acesso por descuido é a falha que essa
| chatice previne.
|
*/

/**
 * @return array<string, list<string>>
 */
function matrizPublicada(): array
{
    return [
        'catalog.view' => ['admin', 'production', 'sales', 'fulfillment', 'finance', 'accountant'],
        'catalog.manage' => ['admin'],

        'inventory.view' => ['admin', 'production', 'sales', 'fulfillment', 'finance'],
        'inventory.move' => ['admin', 'production', 'fulfillment'],
        'inventory.adjust' => ['admin'],

        'production.view' => ['admin', 'production', 'sales', 'fulfillment'],
        'production.execute' => ['admin', 'production'],
        'production.manage' => ['admin'],

        'sales.view' => ['admin', 'sales', 'fulfillment', 'finance'],
        'sales.create' => ['admin', 'sales'],
        'sales.cancel' => ['admin', 'sales'],
        'sales.discount.approve' => ['admin'],

        'fulfillment.execute' => ['admin', 'sales', 'fulfillment'],

        'purchasing.manage' => ['admin'],

        'finance.view' => ['admin', 'finance'],
        'finance.settle' => ['admin', 'finance'],
        'finance.manage' => ['admin', 'finance'],

        'fiscal.view' => ['admin', 'sales', 'fulfillment', 'finance', 'accountant'],
        'fiscal.emit' => ['admin', 'sales', 'fulfillment'],
        'fiscal.cancel' => ['admin'],

        'reports.view' => ['admin', 'finance', 'accountant'],

        'integrations.manage' => ['admin'],
        'users.manage' => ['admin'],
        'audit.view' => ['admin'],
    ];
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('cobre no enum exatamente as permissões da matriz publicada', function () {
    expect(Permission::values())
        ->toEqualCanonicalizing(array_keys(matrizPublicada()));
});

it('concede a cada papel exatamente as permissões da matriz publicada', function () {
    $esperadoPorPapel = [];

    foreach (matrizPublicada() as $permissao => $papeis) {
        foreach ($papeis as $papel) {
            $esperadoPorPapel[$papel][] = $permissao;
        }
    }

    foreach (Role::cases() as $papel) {
        $usuario = User::factory()->create()->assignRoleEnum($papel);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $concedidas = $usuario->getAllPermissions()->pluck('name')->all();

        expect($concedidas)->toEqualCanonicalizing(
            $esperadoPorPapel[$papel->value] ?? [],
            "papel {$papel->value} divergiu da matriz da pasta 19"
        );
    }
});

it('nega tudo a quem não tem papel algum (BR-801)', function () {
    $semPapel = User::factory()->create();

    foreach (Permission::cases() as $permissao) {
        expect($semPapel->can($permissao->value))
            ->toBeFalse("usuário sem papel recebeu {$permissao->value}");
    }
});

it('dá ao contador acesso somente de leitura (BR-803)', function () {
    $contador = User::factory()->create()->assignRoleEnum(Role::Accountant);

    $escritas = array_filter(
        Permission::cases(),
        static fn (Permission $p): bool => ! str_ends_with($p->value, '.view')
    );

    foreach ($escritas as $permissao) {
        expect($contador->can($permissao->value))
            ->toBeFalse("contador recebeu a permissão de escrita {$permissao->value}");
    }

    expect($contador->can(Permission::FiscalView->value))->toBeTrue();
});

it('dá ao admin todas as permissões, inclusive as que ainda não existem', function () {
    $admin = User::factory()->create()->assignRoleEnum(Role::Admin);

    foreach (Permission::cases() as $permissao) {
        expect($admin->can($permissao->value))->toBeTrue();
    }

    // Gate::before do IdentityServiceProvider: uma habilidade que
    // nenhuma Policy conhece ainda também é permitida ao admin.
    expect($admin->can('habilidade.que.ainda.nao.existe'))->toBeTrue();
});

it('não deixa o Gate do admin liberar acesso para os demais papéis', function () {
    $vendas = User::factory()->create()->assignRoleEnum(Role::Sales);

    expect($vendas->can('habilidade.que.ainda.nao.existe'))->toBeFalse();
});

it('exige 2FA de admin e financeiro, e só deles (BR-804)', function () {
    foreach (Role::cases() as $papel) {
        $usuario = User::factory()->create()->assignRoleEnum($papel);

        expect($usuario->requiresTwoFactor())->toBe(
            in_array($papel, [Role::Admin, Role::Finance], strict: true),
            "papel {$papel->value} divergiu da exigência de 2FA"
        );
    }
});

it('exige 2FA de quem acumula um papel que o exige', function () {
    $usuario = User::factory()->create()
        ->assignRoleEnum(Role::Sales, Role::Finance);

    expect($usuario->requiresTwoFactor())->toBeTrue();
});

it('mantém toda permissão no formato modulo.acao', function () {
    foreach (Permission::cases() as $permissao) {
        expect($permissao->value)->toMatch('/^[a-z]+(\.[a-z]+)+$/');
        expect($permissao->module())->not->toBeEmpty();
    }
});
