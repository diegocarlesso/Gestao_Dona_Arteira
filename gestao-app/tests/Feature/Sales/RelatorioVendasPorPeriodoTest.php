<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Repositories\SalesByPeriodReport;
use Database\Seeders\Identity\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Relatório "Vendas por período/canal" — ficha na pasta 20 §3.5
|--------------------------------------------------------------------------
|
| Venda = pedidos confirmados no período, rascunhos e cancelados
| excluídos — mesma definição canônica do glossário do dashboard (pasta
| 21 §2). Divergir dessa lista entre relatórios é bug.
|
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function papel(Role $p): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum($p);
}

/**
 * `create()` deixaria o timestamp em `now()`; aqui o teste precisa
 * escolher a data. `update()` via query builder ignora o ciclo de
 * timestamps do Eloquent — o único jeito confiável de forjar `created_at`.
 */
function pedidoEm(string $data, OrderStatus $status, OrderChannel $canal, string $total): Order
{
    $pedido = Order::factory()->create(['status' => $status, 'channel' => $canal, 'total' => $total]);
    Order::query()->whereKey($pedido->id)->update(['created_at' => $data]);

    return $pedido->fresh();
}

// ------------------------------------------------------ a definição

it('soma só pedidos confirmados em diante, por canal', function () {
    $hoje = Carbon::today();

    pedidoEm($hoje->toDateTimeString(), OrderStatus::Confirmed, OrderChannel::Erp, '100.00');
    pedidoEm($hoje->toDateTimeString(), OrderStatus::Expedido, OrderChannel::Erp, '50.00');
    pedidoEm($hoje->toDateTimeString(), OrderStatus::Confirmed, OrderChannel::WooCommerce, '80.00');
    pedidoEm($hoje->toDateTimeString(), OrderStatus::Draft, OrderChannel::Erp, '999.00');
    pedidoEm($hoje->toDateTimeString(), OrderStatus::Cancelled, OrderChannel::Erp, '999.00');

    $porCanal = app(SalesByPeriodReport::class)->porCanal($hoje->copy()->startOfMonth(), $hoje->copy()->endOfMonth());

    $erp = collect($porCanal)->firstWhere('canal', 'erp');
    $site = collect($porCanal)->firstWhere('canal', 'woocommerce');

    expect($erp['total'])->toBe('150.00')
        ->and($erp['pedidos'])->toBe(2)
        ->and($site['total'])->toBe('80.00');
});

it('calcula o ticket médio dividindo o total pelos pedidos', function () {
    $hoje = Carbon::today();
    pedidoEm($hoje->toDateTimeString(), OrderStatus::Confirmed, OrderChannel::Erp, '100.00');
    pedidoEm($hoje->toDateTimeString(), OrderStatus::Confirmed, OrderChannel::Erp, '50.00');

    $geral = app(SalesByPeriodReport::class)->totalGeral($hoje->copy()->startOfMonth(), $hoje->copy()->endOfMonth());

    expect($geral['ticket_medio'])->toBe('75.00');
});

it('exclui pedidos fora do período', function () {
    pedidoEm(now()->subMonths(2)->toDateTimeString(), OrderStatus::Confirmed, OrderChannel::Erp, '100.00');

    $geral = app(SalesByPeriodReport::class)->totalGeral(now()->startOfMonth(), now()->endOfMonth());

    expect($geral['pedidos'])->toBe(0);
});

// ------------------------------------------------------------- a tela

it('mostra o relatório para quem tem reports.view', function () {
    pedidoEm(now()->toDateTimeString(), OrderStatus::Confirmed, OrderChannel::Erp, '100.00');

    actingAs(papel(Role::Finance))
        ->get('/relatorios/vendas-por-periodo')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/reports/by-period')
            ->where('totalGeral.pedidos', 1)
        );
});

it('barra quem cria pedido mas não tem reports.view (papel sales)', function () {
    // A matriz da pasta 19 não concede reports.view ao papel `sales` —
    // este teste documenta essa fronteira, não é regressão por acidente.
    actingAs(papel(Role::Sales))
        ->get('/relatorios/vendas-por-periodo')
        ->assertForbidden();
});

// --------------------------------------------------------- exportação

it('exporta CSV com BOM e separador de ponto e vírgula', function () {
    pedidoEm(now()->toDateTimeString(), OrderStatus::Confirmed, OrderChannel::Erp, '100.00');

    $resposta = actingAs(papel(Role::Finance))->get('/relatorios/vendas-por-periodo/csv');

    $resposta->assertOk();
    $csv = $resposta->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('Canal;Pedidos;"Total vendido";"Ticket médio"');
});
