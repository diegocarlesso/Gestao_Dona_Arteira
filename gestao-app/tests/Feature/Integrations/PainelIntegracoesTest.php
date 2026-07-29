<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Integrations\WooCommerce\Jobs\ProcessWooOrder;
use App\Modules\Integrations\WooCommerce\Models\WooWebhookEvent;
use Database\Seeders\Identity\RolePermissionSeeder;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Painel de integrações — docs/16 §5
|--------------------------------------------------------------------------
|
| Um monitor operacional dos eventos da sync: resumo por situação, lista
| filtrável e reprocessar. Alçada integrations.manage. Prova o que a tela
| promete: as contagens batem, quem não gerencia não entra, e reprocessar
| limpa o erro e re-enfileira.
|
*/

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

function comIntegracoes(): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum(Role::Admin);
}

/** @param  array<string, mixed>  $extra */
function eventoWoo(array $extra = []): WooWebhookEvent
{
    return WooWebhookEvent::query()->create(array_merge([
        'topic' => 'order.updated',
        'remote_id' => '999',
        'signature_valid' => true,
        'payload' => ['id' => 999],
        'received_at' => now(),
    ], $extra));
}

it('mostra o resumo por situação e a lista para quem gerencia integrações', function () {
    eventoWoo(['processed_at' => null, 'error' => 'falhou']);         // falha
    eventoWoo(['processed_at' => now(), 'error' => 'sem saldo']);     // pendência
    eventoWoo(['signature_valid' => false, 'processed_at' => null]);  // rejeitado

    actingAs(comIntegracoes())
        ->get('/integracoes')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('integrations/panel')
            ->where('resumo.falha', 1)
            ->where('resumo.pendencia', 1)
            ->where('resumo.rejeitado', 1)
            ->where('resumo.na_fila', 0)
            ->has('eventos.data', 3));
});

it('filtra por situação', function () {
    eventoWoo(['processed_at' => null, 'error' => 'falhou']);
    eventoWoo(['processed_at' => now(), 'error' => 'sem saldo']);

    actingAs(comIntegracoes())
        ->get('/integracoes?situacao=falha')
        ->assertInertia(fn (Assert $page) => $page->where('filtro', 'falha')->has('eventos.data', 1));
});

it('nega o painel a quem não gerencia integrações', function () {
    $vendedor = User::factory()->comDoisFatores()->create()->assignRoleEnum(Role::Sales);

    actingAs($vendedor)->get('/integracoes')->assertForbidden();
});

it('reprocessar limpa o erro e re-enfileira o evento', function () {
    Bus::fake([ProcessWooOrder::class]);
    $evento = eventoWoo(['processed_at' => now(), 'error' => 'sem saldo']);

    actingAs(comIntegracoes())
        ->post("/integracoes/eventos/{$evento->id}/reprocessar")
        ->assertRedirect();

    expect($evento->fresh()->error)->toBeNull()
        ->and($evento->fresh()->processed_at)->toBeNull();

    Bus::assertDispatched(ProcessWooOrder::class, fn ($j) => $j->eventId === $evento->id);
});

it('nega reprocessar a quem não gerencia integrações', function () {
    Bus::fake([ProcessWooOrder::class]);
    $evento = eventoWoo(['error' => 'falhou']);
    $vendedor = User::factory()->comDoisFatores()->create()->assignRoleEnum(Role::Sales);

    actingAs($vendedor)
        ->post("/integracoes/eventos/{$evento->id}/reprocessar")
        ->assertForbidden();

    Bus::assertNotDispatched(ProcessWooOrder::class);
});
