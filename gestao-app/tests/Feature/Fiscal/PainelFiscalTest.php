<?php

declare(strict_types=1);

use App\Modules\Fiscal\Jobs\EmitNfeForOrder;
use App\Modules\Fiscal\Models\FiscalSeries;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Sales\Models\Order;
use Database\Seeders\Identity\RolePermissionSeeder;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Painel fiscal — docs/14 §5
|--------------------------------------------------------------------------
|
| Monitor operacional das notas: resumo por situação, lista filtrável e
| reprocessar. `fiscal.view` mostra, `fiscal.emit` reprocessa — a matriz da
| pasta 19 dá as duas coisas a Vendas/Expedição e só a primeira a
| Financeiro/Contador.
|
*/

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

function comFiscal(Role $papel = Role::Sales): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum($papel);
}

/**
 * A mesma série para todas as notas do teste — a factory de `FiscalSeries`
 * sempre nasce `1/homologacao`, e criar uma por nota estouraria a UNIQUE
 * de ambiente.
 *
 * @param  array<string, mixed>  $extra
 */
function notaDe(Order $pedido, array $extra = []): Invoice
{
    $serie = FiscalSeries::query()->firstOrCreate(['series' => '1', 'environment' => 'homologacao'], ['next_number' => 1]);

    return Invoice::factory()->create(array_merge(['order_id' => $pedido->id, 'series_id' => $serie->id], $extra));
}

it('mostra o resumo por situação e a lista para quem tem fiscal.view', function () {
    $pedido = Order::factory()->create();
    notaDe($pedido);
    notaDe($pedido, ['status' => 'rejected', 'rejection_reason' => 'NCM inválido']);
    notaDe($pedido, ['status' => 'authorized', 'number' => 1, 'access_key' => str_repeat('1', 44)]);

    actingAs(comFiscal())
        ->get('/fiscal')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fiscal/panel')
            ->where('resumo.pending', 1)
            ->where('resumo.rejected', 1)
            ->where('resumo.authorized', 1)
            ->where('resumo.cancelled', 0)
            ->has('notas.data', 3));
});

it('mostra o pedido e o cliente da nota, sem importar Sales\Models direto', function () {
    $pedido = Order::factory()->create(['number' => 42]);
    notaDe($pedido);

    actingAs(comFiscal())
        ->get('/fiscal')
        ->assertInertia(fn (Assert $page) => $page
            ->where('notas.data.0.pedido_numero', 42)
            ->where('notas.data.0.pedido_public_id', $pedido->public_id));
});

it('filtra por situação', function () {
    $pedido = Order::factory()->create();
    notaDe($pedido);
    notaDe($pedido, ['status' => 'rejected', 'rejection_reason' => 'motivo']);

    actingAs(comFiscal())
        ->get('/fiscal?status=rejected')
        ->assertInertia(fn (Assert $page) => $page->where('filtro', 'rejected')->has('notas.data', 1));
});

it('nega o painel a quem não tem fiscal.view', function () {
    actingAs(comFiscal(Role::Production))->get('/fiscal')->assertForbidden();
});

it('reenfileira a nota pendente para quem tem fiscal.emit', function () {
    Bus::fake([EmitNfeForOrder::class]);
    $pedido = Order::factory()->create();
    $nota = notaDe($pedido);

    actingAs(comFiscal(Role::Sales))
        ->post("/fiscal/notas/{$nota->public_id}/reprocessar")
        ->assertRedirect();

    Bus::assertDispatched(EmitNfeForOrder::class, fn ($j) => $j->orderId === $pedido->id);
});

it('recusa reprocessar nota já autorizada', function () {
    Bus::fake([EmitNfeForOrder::class]);
    $pedido = Order::factory()->create();
    $nota = notaDe($pedido, ['status' => 'authorized', 'number' => 1, 'access_key' => str_repeat('1', 44)]);

    actingAs(comFiscal(Role::Sales))
        ->post("/fiscal/notas/{$nota->public_id}/reprocessar")
        ->assertSessionHasErrors('reprocessar');

    Bus::assertNotDispatched(EmitNfeForOrder::class);
});

it('nega reprocessar a quem só tem fiscal.view (Contador)', function () {
    Bus::fake([EmitNfeForOrder::class]);
    $pedido = Order::factory()->create();
    $nota = notaDe($pedido);

    actingAs(comFiscal(Role::Accountant))
        ->post("/fiscal/notas/{$nota->public_id}/reprocessar")
        ->assertForbidden();

    Bus::assertNotDispatched(EmitNfeForOrder::class);
});
