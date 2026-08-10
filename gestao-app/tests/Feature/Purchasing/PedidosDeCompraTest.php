<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Finance\Exceptions\TituloInvalido;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Services\FindPayableByOriginService;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Events\PurchaseOrderConfirmed;
use App\Modules\Purchasing\Exceptions\PedidoDeCompraInvalido;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderStatusHistory;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\CancelPurchaseOrderService;
use App\Modules\Purchasing\Services\ConfirmPurchaseOrderService;
use App\Modules\Purchasing\Services\SavePurchaseOrderService;
use App\Modules\Purchasing\Services\SendPurchaseOrderService;
use Database\Seeders\Finance\FinanceCategorySeeder;
use Database\Seeders\Identity\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Pedidos de compra — BR-402, docs/11-Compras (Fase 1 do P0.1)
|--------------------------------------------------------------------------
|
| O que a Fase 1 promete: montar o PC com itens e custo negociado, mandar
| ao fornecedor (registro, não envio automatizado) e **confirmar — que é o
| que vira dívida**: sai exatamente um título a pagar, com o valor do PC e
| o vencimento pelo prazo do fornecedor. Confirmar duas vezes não duplica.
|
| O que a Fase 1 NÃO promete: recebimento físico, quarentena de secagem
| (BR-404), custo médio (BR-402 original) e taxa de quebra (BR-405) — tudo
| Fase 2, junto do Estoque. Nenhum teste aqui deve dar a impressão contrária.
|
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(FinanceCategorySeeder::class);
});

/** Só o Admin tem `purchasing.manage` na matriz da pasta 19. */
function usuarioDeCompras(Role $papel = Role::Admin): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum($papel);
}

/**
 * @param  list<array{product: string, qty: string, unit_cost: string}>  $itens
 * @param  array<string, mixed>  $dados
 */
function abrirPedidoDeCompra(Supplier $fornecedor, array $itens, array $dados = []): PurchaseOrder
{
    return app(SavePurchaseOrderService::class)->handle(
        null,
        array_merge(['supplier_id' => $fornecedor->id], $dados),
        $itens,
    );
}

/** @return list<array{product: string, qty: string, unit_cost: string}> */
function linhaDeCompra(Product $peca, string $qty, string $custo): array
{
    return [['product' => $peca->public_id, 'qty' => $qty, 'unit_cost' => $custo]];
}

// ------------------------------------------------- montagem e totais

it('calcula subtotal e total a partir dos itens', function () {
    $peca = Product::factory()->create();
    $tinta = Product::factory()->create();

    $pc = abrirPedidoDeCompra(Supplier::factory()->create(), [
        ['product' => $peca->public_id, 'qty' => '12', 'unit_cost' => '18.50'],
        ['product' => $tinta->public_id, 'qty' => '3.5', 'unit_cost' => '42.90'],
    ]);

    // 12 × 18,50 = 222,00 · 3,5 × 42,90 = 150,15
    expect($pc->subtotal)->toBe('372.15')
        ->and($pc->total)->toBe('372.15')
        ->and($pc->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($pc->public_id)->toHaveLength(26)
        ->and($pc->number)->toBe(1);
});

it('soma o frete ao total sem misturá-lo no subtotal', function () {
    // Guardá-los separados é o que deixa em aberto a decisão do contador
    // sobre frete entrar (ou não) no custo médio — pasta 11 §7.
    $peca = Product::factory()->create();

    $pc = abrirPedidoDeCompra(
        Supplier::factory()->create(),
        linhaDeCompra($peca, '10', '20.00'),
        ['freight' => '35.50'],
    );

    expect($pc->subtotal)->toBe('200.00')
        ->and($pc->freight)->toBe('35.50')
        ->and($pc->total)->toBe('235.50');
});

it('preserva os centavos que o float arredondaria (ADR-0013)', function () {
    $peca = Product::factory()->create();

    $pc = abrirPedidoDeCompra(Supplier::factory()->create(), linhaDeCompra($peca, '3', '0.07'));

    expect($pc->fresh()->total)->toBe('0.21');
});

it('numera os PCs em sequência', function () {
    $peca = Product::factory()->create();
    $fornecedor = Supplier::factory()->create();

    abrirPedidoDeCompra($fornecedor, linhaDeCompra($peca, '1', '10.00'));
    $segundo = abrirPedidoDeCompra($fornecedor, linhaDeCompra($peca, '1', '10.00'));

    expect($segundo->number)->toBe(2);
});

it('recusa PC sem itens', function () {
    expect(fn () => abrirPedidoDeCompra(Supplier::factory()->create(), []))
        ->toThrow(PedidoDeCompraInvalido::class);

    expect(PurchaseOrder::query()->count())->toBe(0);
});

it('recusa PC que soma zero — o Financeiro recusaria o título depois (BR-501)', function () {
    $peca = Product::factory()->create();

    expect(fn () => abrirPedidoDeCompra(Supplier::factory()->create(), linhaDeCompra($peca, '5', '0')))
        ->toThrow(PedidoDeCompraInvalido::class);

    expect(PurchaseOrder::query()->count())->toBe(0);
});

it('não cria segunda linha para a mesma peça ao editar', function () {
    $peca = Product::factory()->create();
    $fornecedor = Supplier::factory()->create();
    $pc = abrirPedidoDeCompra($fornecedor, linhaDeCompra($peca, '10', '20.00'));

    app(SavePurchaseOrderService::class)->handle(
        $pc,
        ['supplier_id' => $fornecedor->id],
        linhaDeCompra($peca, '25', '19.00'),
    );

    expect($pc->items()->count())->toBe(1)
        ->and($pc->items()->value('qty'))->toBe('25.000')
        ->and($pc->fresh()->total)->toBe('475.00');
});

// --------------------------------------------------------- transições

it('marca como enviado e carimba a data de emissão', function () {
    $peca = Product::factory()->create();
    $pc = abrirPedidoDeCompra(Supplier::factory()->create(), linhaDeCompra($peca, '10', '20.00'));

    app(SendPurchaseOrderService::class)->handle($pc);

    expect($pc->fresh()->status)->toBe(PurchaseOrderStatus::Sent)
        ->and($pc->fresh()->issued_at?->toDateString())->toBe(now()->toDateString());
});

it('grava a trilha de cada transição, com autor', function () {
    $peca = Product::factory()->create();
    $usuario = usuarioDeCompras();
    $pc = abrirPedidoDeCompra(Supplier::factory()->create(), linhaDeCompra($peca, '10', '20.00'));

    app(SendPurchaseOrderService::class)->handle($pc, $usuario->id);
    app(ConfirmPurchaseOrderService::class)->handle($pc, $usuario->id);

    $trilha = PurchaseOrderStatusHistory::query()->where('purchase_order_id', $pc->id)->orderBy('id')->get();

    expect($trilha)->toHaveCount(2)
        ->and($trilha[0]->from_status)->toBe(PurchaseOrderStatus::Draft)
        ->and($trilha[0]->to_status)->toBe(PurchaseOrderStatus::Sent)
        ->and($trilha[1]->to_status)->toBe(PurchaseOrderStatus::Confirmed)
        ->and($trilha[1]->created_by)->toBe($usuario->id);
});

it('não deixa editar PC que já saiu do rascunho', function () {
    $peca = Product::factory()->create();
    $fornecedor = Supplier::factory()->create();
    $pc = abrirPedidoDeCompra($fornecedor, linhaDeCompra($peca, '10', '20.00'));
    app(SendPurchaseOrderService::class)->handle($pc);

    expect(fn () => app(SavePurchaseOrderService::class)->handle(
        $pc,
        ['supplier_id' => $fornecedor->id],
        linhaDeCompra($peca, '99', '20.00'),
    ))->toThrow(PedidoDeCompraInvalido::class);
});

// ----------------------------------------- confirmar gera o título

it('BR-402: confirmar o PC gera exatamente um título com o valor do pedido', function () {
    $peca = Product::factory()->create();
    $fornecedor = Supplier::factory()->comPrazo(30)->create(['legal_name' => 'Gesso Bom Ltda']);
    $pc = abrirPedidoDeCompra($fornecedor, linhaDeCompra($peca, '10', '18.50'), ['freight' => '15.00']);

    app(ConfirmPurchaseOrderService::class)->handle($pc);

    $titulos = Payable::query()->daOrigem('purchase_order', $pc->id)->get();

    expect($pc->fresh()->status)->toBe(PurchaseOrderStatus::Confirmed)
        ->and($titulos)->toHaveCount(1)
        ->and($titulos[0]->amount)->toBe('200.00')          // 185,00 + 15,00 de frete
        ->and($titulos[0]->supplier_name)->toBe('Gesso Bom Ltda')
        ->and($titulos[0]->status)->toBe(Payable::STATUS_OPEN);
});

it('BR-402: o vencimento do título sai da emissão + prazo do fornecedor', function () {
    $peca = Product::factory()->create();
    $fornecedor = Supplier::factory()->comPrazo(28)->create();
    $pc = abrirPedidoDeCompra($fornecedor, linhaDeCompra($peca, '1', '100.00'), ['issued_at' => '2026-08-10']);

    app(ConfirmPurchaseOrderService::class)->handle($pc);

    $titulo = Payable::query()->daOrigem('purchase_order', $pc->id)->firstOrFail();

    expect($titulo->due_date?->toDateString())->toBe('2026-09-07');
});

it('deixa o título sem vencimento quando o fornecedor não tem prazo acertado', function () {
    $peca = Product::factory()->create();
    $fornecedor = Supplier::factory()->semPrazoDePagamento()->create();
    $pc = abrirPedidoDeCompra($fornecedor, linhaDeCompra($peca, '1', '100.00'));

    app(ConfirmPurchaseOrderService::class)->handle($pc);

    expect(Payable::query()->daOrigem('purchase_order', $pc->id)->value('due_date'))->toBeNull();
});

it('confirmar duas vezes não duplica o título (idempotência da guarda de estado)', function () {
    $peca = Product::factory()->create();
    $pc = abrirPedidoDeCompra(Supplier::factory()->create(), linhaDeCompra($peca, '10', '20.00'));

    app(ConfirmPurchaseOrderService::class)->handle($pc);

    expect(fn () => app(ConfirmPurchaseOrderService::class)->handle($pc->fresh()))
        ->toThrow(PedidoDeCompraInvalido::class);

    expect(Payable::query()->daOrigem('purchase_order', $pc->id)->count())->toBe(1);
});

it('confirma direto do rascunho — o PC retroativo da compra por telefone (pasta 11 §7)', function () {
    $peca = Product::factory()->create();
    $pc = abrirPedidoDeCompra(Supplier::factory()->create(), linhaDeCompra($peca, '10', '20.00'));

    app(ConfirmPurchaseOrderService::class)->handle($pc);

    // Sem passar por Enviado, e ainda assim com emissão carimbada — o
    // vencimento precisa contar de algum dia.
    expect($pc->fresh()->status)->toBe(PurchaseOrderStatus::Confirmed)
        ->and($pc->fresh()->issued_at)->not->toBeNull();
});

it('não confirma PC cancelado', function () {
    $peca = Product::factory()->create();
    $pc = abrirPedidoDeCompra(Supplier::factory()->create(), linhaDeCompra($peca, '10', '20.00'));
    app(CancelPurchaseOrderService::class)->handle($pc, 'fornecedor sem material');

    expect(fn () => app(ConfirmPurchaseOrderService::class)->handle($pc->fresh()))
        ->toThrow(PedidoDeCompraInvalido::class);

    expect(Payable::query()->count())->toBe(0);
});

it('anuncia PurchaseOrderConfirmed para quem quiser reagir (ADR-0020)', function () {
    Event::fake([PurchaseOrderConfirmed::class]);

    $peca = Product::factory()->create();
    $pc = abrirPedidoDeCompra(Supplier::factory()->create(), linhaDeCompra($peca, '10', '20.00'));

    app(ConfirmPurchaseOrderService::class)->handle($pc);

    Event::assertDispatched(PurchaseOrderConfirmed::class);
});

it('não deixa PC confirmado sem título nem título sem PC: a falha volta os dois', function () {
    // O título é registrado dentro da transação da confirmação. Sem a
    // categoria padrão semeada, o Financeiro recusa (BR-503) — e o PC tem
    // de continuar como estava, em vez de ficar confirmado sem dívida.
    Payable::query()->delete();
    FinanceCategory::query()->delete();

    $peca = Product::factory()->create();
    $pc = abrirPedidoDeCompra(Supplier::factory()->create(), linhaDeCompra($peca, '10', '20.00'));

    expect(fn () => app(ConfirmPurchaseOrderService::class)->handle($pc))
        ->toThrow(TituloInvalido::class);

    expect($pc->fresh()->status)->toBe(PurchaseOrderStatus::Draft)
        ->and(PurchaseOrderStatusHistory::query()->count())->toBe(0)
        ->and(Payable::query()->count())->toBe(0);
});

// ------------------------------------------------------- cancelar

it('cancela PC em rascunho', function () {
    $peca = Product::factory()->create();
    $pc = abrirPedidoDeCompra(Supplier::factory()->create(), linhaDeCompra($peca, '10', '20.00'));

    app(CancelPurchaseOrderService::class)->handle($pc, 'peça saiu de linha');

    expect($pc->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled)
        ->and(PurchaseOrderStatusHistory::query()->value('reason'))->toBe('peça saiu de linha');
});

it('cancela PC enviado', function () {
    $peca = Product::factory()->create();
    $pc = abrirPedidoDeCompra(Supplier::factory()->create(), linhaDeCompra($peca, '10', '20.00'));
    app(SendPurchaseOrderService::class)->handle($pc);

    app(CancelPurchaseOrderService::class)->handle($pc, 'fornecedor não entregou');

    expect($pc->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled);
});

it('recusa cancelar PC confirmado — a dívida já existe e some sem contrapartida', function () {
    $peca = Product::factory()->create();
    $pc = abrirPedidoDeCompra(Supplier::factory()->create(), linhaDeCompra($peca, '10', '20.00'));
    app(ConfirmPurchaseOrderService::class)->handle($pc);

    expect(fn () => app(CancelPurchaseOrderService::class)->handle($pc->fresh(), 'desisti'))
        ->toThrow(PedidoDeCompraInvalido::class);

    expect($pc->fresh()->status)->toBe(PurchaseOrderStatus::Confirmed)
        ->and(Payable::query()->daOrigem('purchase_order', $pc->id)->count())->toBe(1);
});

// -------------------------------- consulta do título (fronteira)

it('devolve o título da origem pela porta do Financeiro, sem o Compras tocar o model', function () {
    $peca = Product::factory()->create();
    $fornecedor = Supplier::factory()->comPrazo(30)->create(['legal_name' => 'Tintas Colorir Ltda']);
    $pc = abrirPedidoDeCompra($fornecedor, linhaDeCompra($peca, '4', '30.00'));
    app(ConfirmPurchaseOrderService::class)->handle($pc);

    $titulos = app(FindPayableByOriginService::class)->daOrigem('purchase_order', $pc->id);

    expect($titulos)->toHaveCount(1)
        ->and($titulos[0]->amount)->toBe('120.00')
        ->and($titulos[0]->supplierName)->toBe('Tintas Colorir Ltda')
        ->and($titulos[0]->categoryName)->toBe('Compras/Fornecedores')
        ->and(app(FindPayableByOriginService::class)->totalDaOrigem('purchase_order', $pc->id))->toBe('120.00');
});

// ----------------------------------------------------------- telas

it('abre PC pela tela e volta para a edição', function () {
    $peca = Product::factory()->create();
    $fornecedor = Supplier::factory()->create();

    actingAs(usuarioDeCompras())
        ->post('/compras', [
            'supplier' => $fornecedor->public_id,
            'itens' => [['product' => $peca->public_id, 'qty' => '10', 'unit_cost' => '20.00']],
        ])
        ->assertRedirect();

    expect(PurchaseOrder::query()->value('total'))->toBe('200.00');
});

it('recusa abrir PC sem item pela tela', function () {
    actingAs(usuarioDeCompras())
        ->post('/compras', ['supplier' => Supplier::factory()->create()->public_id, 'itens' => []])
        ->assertSessionHasErrors('itens');
});

it('mostra o título gerado na tela do PC confirmado', function () {
    $peca = Product::factory()->create();
    $pc = abrirPedidoDeCompra(Supplier::factory()->comPrazo(30)->create(), linhaDeCompra($peca, '10', '20.00'));
    app(ConfirmPurchaseOrderService::class)->handle($pc);

    actingAs(usuarioDeCompras())
        ->get("/compras/{$pc->public_id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/purchase-orders/edit')
            ->where('pedido.status', 'confirmed')
            ->where('titulos.0.amount', '200.00')
            ->where('titulos.0.categoria', 'Compras/Fornecedores')
        );
});

it('lista os PCs abertos', function () {
    $peca = Product::factory()->create();
    abrirPedidoDeCompra(Supplier::factory()->create(['trade_name' => 'Gesso do Zé']), linhaDeCompra($peca, '10', '20.00'));

    actingAs(usuarioDeCompras())
        ->get('/compras')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/purchase-orders/index')
            ->where('pedidos.data.0.fornecedor', 'Gesso do Zé')
            ->where('pedidos.data.0.total', '200.00')
        );
});

it('confirma pela tela e avisa que a conta a pagar foi gerada', function () {
    $peca = Product::factory()->create();
    $pc = abrirPedidoDeCompra(Supplier::factory()->create(), linhaDeCompra($peca, '10', '20.00'));

    actingAs(usuarioDeCompras())
        ->post("/compras/{$pc->public_id}/confirmar")
        ->assertRedirect();

    expect($pc->fresh()->status)->toBe(PurchaseOrderStatus::Confirmed)
        ->and(Payable::query()->count())->toBe(1);
});

it('exige motivo ao cancelar pela tela', function () {
    $peca = Product::factory()->create();
    $pc = abrirPedidoDeCompra(Supplier::factory()->create(), linhaDeCompra($peca, '10', '20.00'));

    actingAs(usuarioDeCompras())
        ->post("/compras/{$pc->public_id}/cancelar", ['motivo' => ''])
        ->assertSessionHasErrors('motivo');
});

// ------------------------------------------------------ permissões

it('barra quem não pode gerenciar compras de ver os PCs', function () {
    actingAs(usuarioDeCompras(Role::Sales))
        ->get('/compras')
        ->assertForbidden();
});

it('barra quem não pode gerenciar compras de confirmar (gerar dívida)', function () {
    $peca = Product::factory()->create();
    $pc = abrirPedidoDeCompra(Supplier::factory()->create(), linhaDeCompra($peca, '10', '20.00'));

    actingAs(usuarioDeCompras(Role::Finance))
        ->post("/compras/{$pc->public_id}/confirmar")
        ->assertForbidden();

    expect($pc->fresh()->status)->toBe(PurchaseOrderStatus::Draft)
        ->and(Payable::query()->count())->toBe(0);
});
