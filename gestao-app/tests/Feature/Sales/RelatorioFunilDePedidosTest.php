<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Repositories\OrderStatusFunnelReport;
use Database\Seeders\Identity\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Relatório "Funil de pedidos por status" — ficha na pasta 20 §3.6
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function usuarioComReports(Role $p): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum($p);
}

it('conta os pedidos por status, com zero para status sem pedido', function () {
    Order::factory()->create(['status' => OrderStatus::Confirmed]);
    Order::factory()->create(['status' => OrderStatus::Confirmed]);
    Order::factory()->create(['status' => OrderStatus::Cancelled]);

    $contagens = collect(app(OrderStatusFunnelReport::class)->contagens())->keyBy('status');

    expect($contagens['confirmed']['pedidos'])->toBe(2)
        ->and($contagens['cancelled']['pedidos'])->toBe(1)
        ->and($contagens['draft']['pedidos'])->toBe(0)
        ->and($contagens)->toHaveCount(7);
});

it('mostra o relatório para quem tem reports.view', function () {
    Order::factory()->create(['status' => OrderStatus::Confirmed]);

    actingAs(usuarioComReports(Role::Finance))
        ->get('/relatorios/funil-de-pedidos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sales/reports/status-funnel'));
});

it('barra quem não tem reports.view', function () {
    actingAs(usuarioComReports(Role::Sales))
        ->get('/relatorios/funil-de-pedidos')
        ->assertForbidden();
});

it('exporta CSV com BOM', function () {
    Order::factory()->create(['status' => OrderStatus::Confirmed]);

    $resposta = actingAs(usuarioComReports(Role::Finance))->get('/relatorios/funil-de-pedidos/csv');

    $resposta->assertOk();
    expect($resposta->streamedContent())->toStartWith("\xEF\xBB\xBF");
});
