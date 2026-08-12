<?php

declare(strict_types=1);

use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\Finance\FinanceCategorySeeder;
use Database\Seeders\Identity\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Telas do Financeiro — docs/12-Financeiro §3/§6
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(FinanceCategorySeeder::class);
});

function comFinanceiro(Role $papel = Role::Finance): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum($papel);
}

it('lista títulos a receber para quem tem finance.view', function () {
    Receivable::factory()->create(['due_date' => now()->subDays(20)->toDateString()]);

    actingAs(comFinanceiro())
        ->get('/financeiro?tipo=receivable')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/titles/index')
            ->where('tipo', 'receivable')
            ->has('titulos.data', 1)
            ->where('titulos.data.0.faixa_aging', '16-30')
        );
});

it('barra quem não tem finance.view', function () {
    actingAs(comFinanceiro(Role::Production))
        ->get('/financeiro')
        ->assertForbidden();
});

it('dá baixa num título a receber (finance.settle)', function () {
    $titulo = Receivable::factory()->create(['amount' => '100.00']);
    $conta = FinanceAccount::factory()->create();

    actingAs(comFinanceiro())
        ->post("/financeiro/titulos/receivable/{$titulo->public_id}/baixa", [
            'account_id' => $conta->id,
            'amount' => '100.00',
            'settled_at' => now()->toDateString(),
        ])
        ->assertRedirect();

    expect($titulo->fresh()->status)->toBe(Receivable::STATUS_SETTLED);
});

it('barra baixa de quem só tem finance.view', function () {
    $titulo = Payable::factory()->create();
    $conta = FinanceAccount::factory()->create();

    actingAs(comFinanceiro(Role::Accountant))
        ->post("/financeiro/titulos/payable/{$titulo->public_id}/baixa", [
            'account_id' => $conta->id,
            'amount' => '10.00',
            'settled_at' => now()->toDateString(),
        ])
        ->assertForbidden();
});

it('lista, cria e atualiza contas financeiras (finance.manage)', function () {
    $usuario = comFinanceiro();

    actingAs($usuario)
        ->post('/financeiro/contas', ['name' => 'Caixa', 'type' => 'cash'])
        ->assertRedirect();

    $conta = FinanceAccount::query()->where('name', 'Caixa')->firstOrFail();

    actingAs($usuario)
        ->get('/financeiro/contas')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/accounts/index')
            ->has('contas', 1)
        );

    actingAs($usuario)
        ->put("/financeiro/contas/{$conta->public_id}", ['name' => 'Caixa da loja', 'type' => 'cash'])
        ->assertRedirect();

    expect($conta->fresh()->name)->toBe('Caixa da loja');
});

it('mostra o fluxo de caixa', function () {
    actingAs(comFinanceiro())
        ->get('/financeiro/fluxo-de-caixa')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/cash-flow/index')
            ->has('fluxo.projetado')
            ->has('fluxo.realizado')
            ->where('podeExportar', true)
        );
});

it('exporta o fluxo de caixa em CSV — ficha da pasta 20 §3.4', function () {
    $resposta = actingAs(comFinanceiro())->get('/financeiro/fluxo-de-caixa/csv');

    $resposta->assertOk();
    expect($resposta->streamedContent())
        ->toStartWith("\xEF\xBB\xBF")
        ->toContain('Janela;"Projetado (a receber - a pagar)";"Realizado (baixas)"');
});

it('lista e cria categorias', function () {
    actingAs(comFinanceiro())
        ->get('/financeiro/categorias')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('finance/categories/index'));

    actingAs(comFinanceiro())
        ->post('/financeiro/categorias', ['name' => 'Impostos', 'type' => 'payable'])
        ->assertRedirect();

    expect(FinanceCategory::query()->where('name', 'Impostos')->exists())->toBeTrue();
});
