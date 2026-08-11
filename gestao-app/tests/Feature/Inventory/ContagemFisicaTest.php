<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\StockCountStatus;
use App\Modules\Inventory\Exceptions\ContagemInvalida;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Services\ApproveStockCountService;
use App\Modules\Inventory\Services\OpenStockCountService;
use App\Modules\Inventory\Services\RecordMovementService;
use Database\Seeders\Identity\RolePermissionSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Contagem física — BR-205, docs/09-Estoque §5
|--------------------------------------------------------------------------
|
| É por aqui que o saldo inicial do ERP nasce. A regra que dá sentido à
| entidade: aprovador ≠ contador. Contagem que a mesma pessoa lança e
| aprova não é controle, é digitação com etapa extra.
|
*/

function pessoa(Role $papel = Role::Admin): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum($papel);
}

function abrirContagem(Location $local, ?int $por = null): StockCount
{
    return app(OpenStockCountService::class)->handle($local, $por);
}

function aprovar(StockCount $contagem, int $por): int
{
    return app(ApproveStockCountService::class)->handle($contagem, $por);
}

// ------------------------------------------------------ o congelamento

it('congela a lista com o saldo do momento em que abriu', function () {
    $local = Location::factory()->padrao()->create();
    $produto = Product::factory()->create();

    app(RecordMovementService::class)->handle($produto->id, $local->id, MovementType::ProductionOutput, '10');

    $contagem = abrirContagem($local);

    expect($contagem->items()->count())->toBe(1)
        ->and($contagem->items()->value('qty_system'))->toBe('10.000')
        ->and($contagem->items()->value('qty_counted'))->toBeNull();
});

it('inclui produto que nunca se moveu, com sistema zerado', function () {
    $local = Location::factory()->padrao()->create();
    Product::factory()->create();

    $contagem = abrirContagem($local);

    // Produto sem linha de saldo é o caso mais comum no inventário
    // inicial: 754 peças e nenhum movimento.
    expect($contagem->items()->value('qty_system'))->toBe('0.000');
});

it('nao deixa abrir duas contagens no mesmo local', function () {
    $local = Location::factory()->padrao()->create();
    Product::factory()->create();

    abrirContagem($local);

    // Duas abertas produziriam ajustes calculados sobre o mesmo saldo
    // congelado, e a segunda desfaria a primeira em silêncio.
    expect(fn () => abrirContagem($local))->toThrow(ContagemInvalida::class);
});

// ---------------------------------------------------------- BR-205

it('recusa que o contador aprove a propria contagem', function () {
    $local = Location::factory()->padrao()->create();
    Product::factory()->create();
    $contador = pessoa();

    $contagem = abrirContagem($local, $contador->id);
    $contagem->items()->update(['qty_counted' => '5']);
    $contagem->forceFill(['status' => StockCountStatus::AwaitingApproval])->save();

    expect(fn () => aprovar($contagem->fresh(), $contador->id))
        ->toThrow(ContagemInvalida::class);
});

it('aceita aprovacao de outra pessoa', function () {
    $local = Location::factory()->padrao()->create();
    Product::factory()->create();
    $contador = pessoa();
    $aprovador = pessoa();

    $contagem = abrirContagem($local, $contador->id);
    $contagem->items()->update(['qty_counted' => '5']);
    $contagem->forceFill(['status' => StockCountStatus::AwaitingApproval])->save();

    $ajustes = aprovar($contagem->fresh(), $aprovador->id);

    expect($ajustes)->toBe(1)
        ->and($contagem->fresh()->status)->toBe(StockCountStatus::Approved)
        ->and($contagem->fresh()->approved_by)->toBe($aprovador->id);
});

// ------------------------------------------------- a divergencia vira movimento

it('transforma cada divergencia em ajuste com o motivo da contagem', function () {
    $local = Location::factory()->padrao()->create();
    $produto = Product::factory()->create();
    app(RecordMovementService::class)->handle($produto->id, $local->id, MovementType::ProductionOutput, '10');

    $contagem = abrirContagem($local, pessoa()->id);
    $contagem->items()->update(['qty_counted' => '7']);
    $contagem->forceFill(['status' => StockCountStatus::AwaitingApproval])->save();

    aprovar($contagem->fresh(), pessoa()->id);

    $ajuste = InventoryMovement::query()->where('type', MovementType::AdjustmentOut->value)->first();

    expect($ajuste->qty)->toBe('-3.000')
        ->and($ajuste->notes)->toContain($contagem->public_id)
        ->and($ajuste->reference_type)->toBe('stock_count')
        ->and(InventoryBalance::query()->value('qty_on_hand'))->toBe('7.000');
});

it('gera ajuste de entrada quando a prateleira tem mais que o sistema', function () {
    $local = Location::factory()->padrao()->create();
    $produto = Product::factory()->create();

    $contagem = abrirContagem($local, pessoa()->id);
    $contagem->items()->update(['qty_counted' => '4']);
    $contagem->forceFill(['status' => StockCountStatus::AwaitingApproval])->save();

    aprovar($contagem->fresh(), pessoa()->id);

    // `value()` do Eloquent aplica o cast, então volta o enum.
    expect(InventoryMovement::query()->value('type'))->toBe(MovementType::AdjustmentIn)
        ->and(InventoryBalance::query()->value('qty_on_hand'))->toBe('4.000');
});

it('nao gera movimento onde a contagem confirma o sistema', function () {
    $local = Location::factory()->padrao()->create();
    $produto = Product::factory()->create();
    app(RecordMovementService::class)->handle($produto->id, $local->id, MovementType::ProductionOutput, '10');

    $contagem = abrirContagem($local, pessoa()->id);
    $contagem->items()->update(['qty_counted' => '10']);
    $contagem->forceFill(['status' => StockCountStatus::AwaitingApproval])->save();

    $ajustes = aprovar($contagem->fresh(), pessoa()->id);

    // Só o movimento da produção — a contagem não polui o ledger com
    // ajustes de zero.
    expect($ajustes)->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(1);
});

it('ignora o item que ninguem contou', function () {
    $local = Location::factory()->padrao()->create();
    $contado = Product::factory()->create();
    $naoContado = Product::factory()->create();

    $contagem = abrirContagem($local, pessoa()->id);
    $contagem->items()->where('product_id', $contado->id)->update(['qty_counted' => '3']);
    $contagem->forceFill(['status' => StockCountStatus::AwaitingApproval])->save();

    aprovar($contagem->fresh(), pessoa()->id);

    // `null` não é zero. Tratar não-contado como zero zeraria o estoque de
    // tudo que a equipe não chegou a percorrer — numa contagem de 754
    // peças, isso é a maior parte do catálogo.
    expect(InventoryMovement::query()->where('product_id', $naoContado->id)->count())->toBe(0)
        ->and(InventoryMovement::query()->where('product_id', $contado->id)->count())->toBe(1);
});

it('recusa aprovar contagem que ainda esta aberta', function () {
    $local = Location::factory()->padrao()->create();
    Product::factory()->create();

    $contagem = abrirContagem($local, pessoa()->id);
    $contagem->items()->update(['qty_counted' => '5']);

    expect(fn () => aprovar($contagem->fresh(), pessoa()->id))->toThrow(ContagemInvalida::class);
});

// -------------------------------------------------------- o cancelamento

it('cancela contagem que ainda esta em contagem', function () {
    $local = Location::factory()->padrao()->create();
    Product::factory()->create();
    $contagem = abrirContagem($local, pessoa()->id);

    actingAs(pessoa())
        ->post("/estoque/contagens/{$contagem->public_id}/cancelar")
        ->assertRedirect();

    expect($contagem->fresh()->status)->toBe(StockCountStatus::Cancelled);
});

it('cancela contagem que ja foi encerrada, aguardando aprovacao', function () {
    $local = Location::factory()->padrao()->create();
    Product::factory()->create();
    $contagem = abrirContagem($local, pessoa()->id);
    $contagem->forceFill(['status' => StockCountStatus::AwaitingApproval])->save();

    actingAs(pessoa())
        ->post("/estoque/contagens/{$contagem->public_id}/cancelar")
        ->assertRedirect();

    expect($contagem->fresh()->status)->toBe(StockCountStatus::Cancelled);
});

it('recusa cancelar contagem ja aprovada', function () {
    $local = Location::factory()->padrao()->create();
    Product::factory()->create();
    $contagem = abrirContagem($local, pessoa()->id);
    $contagem->items()->update(['qty_counted' => '5']);
    $contagem->forceFill(['status' => StockCountStatus::AwaitingApproval])->save();
    aprovar($contagem->fresh(), pessoa()->id);

    // Aprovada já virou movimento no ledger — cancelar não desfaria o
    // ajuste, só esconderia de onde ele veio.
    actingAs(pessoa())
        ->post("/estoque/contagens/{$contagem->public_id}/cancelar")
        ->assertRedirect()
        ->assertSessionHasErrors('status');

    expect($contagem->fresh()->status)->toBe(StockCountStatus::Approved);
});

// ------------------------------------------------------------- as telas

it('grava as quantidades digitadas', function () {
    $local = Location::factory()->padrao()->create();
    Product::factory()->create();
    $contagem = abrirContagem($local);
    $item = $contagem->items()->first();

    actingAs(pessoa())
        ->post("/estoque/contagens/{$contagem->public_id}/contar", ['quantidades' => [$item->id => '9']])
        ->assertRedirect();

    expect($item->fresh()->qty_counted)->toBe('9.000');
});

it('deixa limpar uma quantidade digitada por engano', function () {
    $local = Location::factory()->padrao()->create();
    Product::factory()->create();
    $contagem = abrirContagem($local);
    $item = $contagem->items()->first();
    $item->update(['qty_counted' => '9']);

    // Voltar ao estado "não contei" precisa ser possível, e não é o mesmo
    // que registrar zero.
    actingAs(pessoa())
        ->post("/estoque/contagens/{$contagem->public_id}/contar", ['quantidades' => [$item->id => null]])
        ->assertRedirect();

    expect($item->fresh()->qty_counted)->toBeNull();
});

it('barra quem so pode ver estoque de abrir contagem', function () {
    Location::factory()->padrao()->create();

    // Produção tem `inventory.move` e não tem `inventory.adjust` — é a
    // "permissão específica" a que a BR-201 se refere.
    actingAs(pessoa(Role::Production))
        ->post('/estoque/contagens', ['local' => Location::query()->value('public_id')])
        ->assertForbidden();
});

it('esconde o botao de aprovar de quem contou', function () {
    $local = Location::factory()->padrao()->create();
    Product::factory()->create();
    $contador = pessoa();

    $contagem = abrirContagem($local, $contador->id);
    $contagem->forceFill(['status' => StockCountStatus::AwaitingApproval])->save();

    actingAs($contador)
        ->get("/estoque/contagens/{$contagem->public_id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('podeAprovar', false));
});
