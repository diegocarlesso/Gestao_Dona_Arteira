<?php

declare(strict_types=1);

use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Repositories\TitlesAgingReport;
use App\Modules\Finance\Services\RegisterSettlementService;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\Identity\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Relatório "Aging (a receber/pagar)" — ficha na pasta 20 §3.3
|--------------------------------------------------------------------------
|
| Mesma definição de faixa que a tela operacional; a diferença é ser
| consulta dedicada, sem paginação, somada e exportável.
|
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function usuarioComPapel(Role $papel): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum($papel);
}

// ------------------------------------------------------ a definição

it('classifica cada título na faixa de aging certa e soma por faixa', function () {
    $hoje = now()->startOfDay();

    Receivable::factory()->create(['amount' => '100.00', 'due_date' => $hoje->copy()->addDays(10)->toDateString()]); // a vencer
    Receivable::factory()->create(['amount' => '50.00', 'due_date' => $hoje->copy()->subDays(5)->toDateString()]);   // 1-15
    Receivable::factory()->create(['amount' => '30.00', 'due_date' => $hoje->copy()->subDays(20)->toDateString()]);  // 16-30
    Receivable::factory()->create(['amount' => '20.00', 'due_date' => $hoje->copy()->subDays(40)->toDateString()]);  // 30+

    $relatorio = app(TitlesAgingReport::class);
    $linhas = $relatorio->linhas('receivable');
    $totais = $relatorio->totaisPorFaixa($linhas, $hoje);

    expect($totais['a_vencer'])->toBe('100.00')
        ->and($totais['1-15'])->toBe('50.00')
        ->and($totais['16-30'])->toBe('30.00')
        ->and($totais['30+'])->toBe('20.00')
        ->and($totais['total'])->toBe('200.00');
});

it('soma o saldo aberto, não o valor original, quando há baixa parcial', function () {
    $titulo = Receivable::factory()->create(['amount' => '100.00', 'due_date' => now()->subDays(5)->toDateString()]);
    $conta = FinanceAccount::factory()->create();
    app(RegisterSettlementService::class)->handle('receivable', $titulo->id, $conta->id, '40.00', now()->toDateString());

    $relatorio = app(TitlesAgingReport::class);
    $linhas = $relatorio->linhas('receivable');
    $totais = $relatorio->totaisPorFaixa($linhas);

    expect($totais['1-15'])->toBe('60.00');
});

it('não mistura receivable e payable no mesmo filtro', function () {
    Receivable::factory()->create(['amount' => '10.00']);
    Payable::factory()->create(['amount' => '20.00']);

    $relatorio = app(TitlesAgingReport::class);

    expect($relatorio->linhas('receivable'))->toHaveCount(1)
        ->and($relatorio->linhas('payable'))->toHaveCount(1);
});

it('filtra por janela de vencimento', function () {
    $hoje = now()->startOfDay();
    Receivable::factory()->create(['due_date' => $hoje->copy()->addDays(5)->toDateString()]);
    Receivable::factory()->create(['due_date' => $hoje->copy()->addDays(50)->toDateString()]);

    $dentro = app(TitlesAgingReport::class)->linhas('receivable', $hoje->toDateString(), $hoje->copy()->addDays(10)->toDateString());

    expect($dentro)->toHaveCount(1);
});

// ------------------------------------------------------------- a tela

it('mostra o relatório para quem tem reports.view', function () {
    Receivable::factory()->create(['amount' => '100.00', 'due_date' => now()->subDays(5)->toDateString()]);

    actingAs(usuarioComPapel(Role::Finance))
        ->get('/financeiro/relatorios/vencimentos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/reports/aging')
            ->where('tipo', 'receivable')
            ->has('titulos', 1)
            ->where('totais.1-15', '100.00')
        );
});

it('barra quem não tem reports.view', function () {
    actingAs(usuarioComPapel(Role::Production))
        ->get('/financeiro/relatorios/vencimentos')
        ->assertForbidden();
});

// --------------------------------------------------------- exportação

it('exporta CSV com BOM e separador de ponto e vírgula', function () {
    Receivable::factory()->create(['amount' => '100.00', 'customer_name' => 'Cliente Exportação', 'due_date' => now()->subDays(5)->toDateString()]);

    $resposta = actingAs(usuarioComPapel(Role::Finance))->get('/financeiro/relatorios/vencimentos/csv');

    $resposta->assertOk();
    $csv = $resposta->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('Cliente;Categoria;Vencimento;Faixa;"Saldo aberto"')
        ->and($csv)->toContain('Cliente Exportação');
});

it('não deixa quem não tem reports.view baixar o CSV', function () {
    actingAs(usuarioComPapel(Role::Production))
        ->get('/financeiro/relatorios/vencimentos/csv')
        ->assertForbidden();
});
