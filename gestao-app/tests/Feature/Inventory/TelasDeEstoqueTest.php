<?php

declare(strict_types=1);

use App\Modules\Catalog\DTO\ProductSummary;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductLookupService;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Repositories\StockPosition;
use App\Modules\Inventory\Services\RecordMovementService;
use Database\Seeders\Identity\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Telas de estoque — posição e extrato (docs/09-Estoque §8)
|--------------------------------------------------------------------------
|
| O extrato é a tela de depuração número 1 do módulo: responde "por que o
| saldo está assim?", que é a pergunta que o legado não respondia.
|
*/

function operador(Role $papel = Role::Admin): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum($papel);
}

function moverNaTela(Product $p, Location $l, MovementType $tipo, string $qty, ...$extra): void
{
    app(RecordMovementService::class)->handle($p->id, $l->id, $tipo, $qty, ...$extra);
}

// ------------------------------------------------------- a posição

it('mostra o saldo com o nome vindo do catalogo', function () {
    $produto = Product::factory()->create(['name' => 'Buda sidarta 11 cm']);
    $local = Location::factory()->padrao()->create();

    moverNaTela($produto, $local, MovementType::ProductionOutput, '12');

    actingAs(operador())
        ->get('/estoque')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/position')
            ->where('saldos.data.0.produto.name', 'Buda sidarta 11 cm')
            ->where('saldos.data.0.on_hand', '12.000')
            ->where('saldos.data.0.disponivel', '12.000')
        );
});

it('filtra a posicao pela busca do catalogo', function () {
    $local = Location::factory()->padrao()->create();
    $buda = Product::factory()->create(['name' => 'Buda sidarta 11 cm']);
    $elefante = Product::factory()->create(['name' => 'Elefante manto 22 cm']);

    moverNaTela($buda, $local, MovementType::ProductionOutput, '5');
    moverNaTela($elefante, $local, MovementType::ProductionOutput, '5');

    actingAs(operador())
        ->get('/estoque?busca=elefante')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('saldos.data', 1)
            ->where('saldos.data.0.produto.name', 'Elefante manto 22 cm')
        );
});

it('devolve lista vazia quando a busca nao acha nada', function () {
    $local = Location::factory()->padrao()->create();
    $produto = Product::factory()->create(['name' => 'Buda sidarta 11 cm']);
    moverNaTela($produto, $local, MovementType::ProductionOutput, '5');

    // Busca sem resultado tem de mostrar zero linhas. Tratar "não achou"
    // como "sem filtro" faria a tela devolver o estoque inteiro, que é o
    // oposto do que a pessoa pediu.
    actingAs(operador())
        ->get('/estoque?busca=zzzznaoexiste')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('saldos.data', 0));
});

it('barra quem nao pode ver estoque', function () {
    // Contador não tem `inventory.view` na matriz da pasta 19.
    actingAs(operador(Role::Accountant))
        ->get('/estoque')
        ->assertForbidden();
});

// -------------------------------------------------------- o extrato

it('mostra o saldo depois de cada movimento', function () {
    $produto = Product::factory()->create();
    $local = Location::factory()->padrao()->create();

    moverNaTela($produto, $local, MovementType::ProductionOutput, '10');
    moverNaTela($produto, $local, MovementType::SaleShipment, '4');
    moverNaTela($produto, $local, MovementType::ProductionOutput, '1');

    actingAs(operador())
        ->get("/estoque/produtos/{$produto->public_id}/extrato")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/statement')
            ->where('saldo.on_hand', '7.000')
            // Do mais recente ao mais antigo: 7 → 6 → 10.
            ->where('movimentos.data.0.saldo_apos', '7.000')
            ->where('movimentos.data.1.saldo_apos', '6.000')
            ->where('movimentos.data.2.saldo_apos', '10.000')
        );
});

it('mantem o saldo corrente correto na segunda pagina', function () {
    $produto = Product::factory()->create();
    $local = Location::factory()->padrao()->create();

    // 31 entradas de 1 — a página tem 30, então a segunda mostra a
    // primeira movimentação, cujo saldo depois é exatamente 1.
    for ($i = 0; $i < 31; $i++) {
        moverNaTela($produto, $local, MovementType::ProductionOutput, '1');
    }

    actingAs(operador())
        ->get("/estoque/produtos/{$produto->public_id}/extrato?page=2")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('movimentos.data', 1)
            ->where('movimentos.data.0.saldo_apos', '1.000')
        );
});

it('mostra estado vazio quando a peca nunca se moveu', function () {
    $produto = Product::factory()->create();
    Location::factory()->padrao()->create();

    actingAs(operador())
        ->get("/estoque/produtos/{$produto->public_id}/extrato")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('movimentos.data', 0)
            ->where('saldo.on_hand', '0.000')
        );
});

it('devolve 404 para produto inexistente', function () {
    Location::factory()->padrao()->create();

    actingAs(operador())
        ->get('/estoque/produtos/01ARZ3NDEKTSV4RRFFQ69G5FAV/extrato')
        ->assertNotFound();
});

// ------------------------------------------------------ a fronteira

it('devolve resumo do produto sem entregar o model', function () {
    $produto = Product::factory()->create(['name' => 'Trio da sabedoria 14 cm']);

    $resumo = app(ProductLookupService::class)->resumo($produto->id);

    // O ADR-0020 avisa que `arch()` verifica namespace e não semântica:
    // um Service que devolvesse `Product` passaria no teste de
    // arquitetura enquanto vazava o acoplamento inteiro.
    expect($resumo)->toBeInstanceOf(ProductSummary::class)
        ->and($resumo->name)->toBe('Trio da sabedoria 14 cm')
        ->and($resumo->sku)->toBe($produto->sku);
});

it('separa o extrato por local', function () {
    $produto = Product::factory()->create();
    $atelie = Location::factory()->padrao()->create();
    $deposito = Location::factory()->create(['name' => 'Depósito']);

    moverNaTela($produto, $atelie, MovementType::ProductionOutput, '10');
    moverNaTela($produto, $deposito, MovementType::ProductionOutput, '3');

    $extrato = app(StockPosition::class)->extrato($produto->id, $deposito->id);

    expect($extrato->total())->toBe(1)
        ->and($extrato->getCollection()->first()->getAttribute('saldo_apos'))->toBe('3.000');
});
