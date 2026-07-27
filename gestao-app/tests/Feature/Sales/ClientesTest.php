<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Sales\Enums\CustomerOrigin;
use App\Modules\Sales\Events\CustomerRegistered;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;
use App\Support\Documento;
use Database\Seeders\Identity\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Clientes — BR-001, docs/10-Vendas, pasta 25 §3 (LGPD)
|--------------------------------------------------------------------------
|
| A regra que molda o cadastro: CPF/CNPJ é obrigatório para faturar, não
| para existir. Balcão sem nota compra sem documento; a falta bloqueia a
| NF-e, no momento em que ela importa.
|
*/

function vendedor(Role $papel = Role::Sales): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum($papel);
}

/** @return array<string, mixed> */
function dadosDeCliente(array $extra = []): array
{
    return array_merge([
        'type' => 'individual',
        'name' => 'Maria de Souza',
        'is_wholesale' => false,
    ], $extra);
}

// ------------------------------------------------ o documento (BR-001)

it('valida CPF e CNPJ pelo digito verificador', function () {
    expect(Documento::valido('529.982.247-25'))->toBeTrue()      // CPF válido conhecido
        ->and(Documento::valido('529.982.247-26'))->toBeFalse()   // dígito trocado
        ->and(Documento::valido('111.111.111-11'))->toBeFalse()   // repetido passa na conta, não é CPF
        ->and(Documento::valido('11.222.333/0001-81'))->toBeTrue()
        ->and(Documento::valido('11.222.333/0001-82'))->toBeFalse()
        ->and(Documento::valido('123'))->toBeFalse();
});

it('guarda o documento sem mascara', function () {
    actingAs(vendedor())
        ->post('/clientes', dadosDeCliente(['doc' => '529.982.247-25']))
        ->assertRedirect();

    // Com máscara, o mesmo CPF escrito de dois jeitos passaria pela
    // unicidade e viraria dois clientes.
    expect(Customer::query()->value('doc'))->toBe('52998224725');
});

it('recusa documento invalido', function () {
    actingAs(vendedor())
        ->post('/clientes', dadosDeCliente(['doc' => '529.982.247-26']))
        ->assertSessionHasErrors('doc');

    expect(Customer::query()->count())->toBe(0);
});

it('aceita cliente de balcao sem documento', function () {
    // A BR-001 exige documento para faturar, não para existir. Exigi-lo no
    // cadastro empurraria a operação a inventar número para fechar a
    // venda.
    actingAs(vendedor())
        ->post('/clientes', dadosDeCliente())
        ->assertRedirect();

    $cliente = Customer::query()->first();

    expect($cliente->doc)->toBeNull()
        ->and($cliente->pendencias())->toContain('sem CPF/CNPJ');
});

it('nao deixa dois clientes com o mesmo documento', function () {
    Customer::factory()->create(['doc' => '52998224725']);

    actingAs(vendedor())
        ->post('/clientes', dadosDeCliente(['doc' => '529.982.247-25']))
        ->assertSessionHasErrors('doc');
});

it('deixa salvar o proprio cliente sem colidir consigo mesmo', function () {
    $cliente = Customer::factory()->create(['doc' => '52998224725']);

    actingAs(vendedor())
        ->put("/clientes/{$cliente->public_id}", dadosDeCliente(['doc' => '529.982.247-25', 'name' => 'Nome corrigido']))
        ->assertRedirect();

    expect($cliente->fresh()->name)->toBe('Nome corrigido');
});

// ---------------------------------------------------------- endereços

it('grava o endereco junto do cliente', function () {
    actingAs(vendedor())
        ->post('/clientes', dadosDeCliente(['enderecos' => [[
            'label' => 'Casa',
            'zip' => '95700-000',
            'street' => 'Rua das Pedras',
            'number' => '42',
            'city' => 'Bento Gonçalves',
            'state' => 'rs',
            'is_default_shipping' => true,
        ]]]))
        ->assertRedirect();

    $endereco = CustomerAddress::query()->first();

    expect($endereco->zip)->toBe('95700000')     // sem máscara
        ->and($endereco->state)->toBe('RS')       // sempre maiúscula
        ->and($endereco->resumo())->toContain('Bento Gonçalves/RS');
});

it('mantem um unico endereco de entrega padrao', function () {
    $cliente = Customer::factory()->create();
    $primeiro = CustomerAddress::factory()->create(['customer_id' => $cliente->id, 'is_default_shipping' => true]);
    $segundo = CustomerAddress::factory()->create(['customer_id' => $cliente->id, 'is_default_shipping' => true]);

    // Dois padrões fariam a escolha voltar a ser "o primeiro que
    // aparecer", e o pedido sairia para o endereço errado sem que ninguém
    // tivesse decidido isso.
    expect($primeiro->fresh()->is_default_shipping)->toBeFalse()
        ->and($segundo->fresh()->is_default_shipping)->toBeTrue();
});

it('remove o endereco que sumiu do formulario', function () {
    $cliente = Customer::factory()->create();
    CustomerAddress::factory()->count(1)->create(['customer_id' => $cliente->id]);

    actingAs(vendedor())
        ->put("/clientes/{$cliente->public_id}", dadosDeCliente(['enderecos' => [[
            'zip' => '90000000',
            'street' => 'Rua Nova',
            'city' => 'Porto Alegre',
            'state' => 'RS',
        ]]]))
        ->assertRedirect();

    expect($cliente->addresses()->count())->toBe(1)
        ->and($cliente->addresses()->value('street'))->toBe('Rua Nova');
});

// ------------------------------------------------------- o cadastro

it('nasce com origem ERP e anuncia o cadastro', function () {
    Event::fake([CustomerRegistered::class]);

    actingAs(vendedor())->post('/clientes', dadosDeCliente())->assertRedirect();

    expect(Customer::query()->value('origin'))->toBe(CustomerOrigin::Erp);

    // É por aqui que a sincronização com o site vai ouvir (pasta 16), sem
    // que Vendas conheça a integração.
    Event::assertDispatched(CustomerRegistered::class);
});

it('inativa em vez de apagar', function () {
    $cliente = Customer::factory()->create();

    actingAs(vendedor())->delete("/clientes/{$cliente->public_id}")->assertRedirect();

    // O histórico de compra aponta para ele, e a NF-e emitida guarda o
    // destinatário por obrigação fiscal.
    expect(Customer::query()->count())->toBe(0)
        ->and(Customer::withTrashed()->count())->toBe(1);
});

it('acha o cliente pelo documento digitado com mascara', function () {
    Customer::factory()->create(['doc' => '52998224725', 'name' => 'Maria']);

    actingAs(vendedor())
        ->get('/clientes?busca=529.982')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('clientes.data', 1));
});

it('lista as pendencias que impedem faturar', function () {
    Customer::factory()->create(['doc' => null]);

    actingAs(vendedor())
        ->get('/clientes')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/customers/index')
            ->where('contagens.sem_doc', 1)
            ->where('contagens.sem_endereco', 1)
        );
});

// ------------------------------------------------------- permissões

it('barra quem nao pode ver vendas', function () {
    // Produção não tem `sales.view` — e a lista de clientes com documento
    // e telefone é exatamente o que a LGPD manda não expor a todos.
    actingAs(vendedor(Role::Production))
        ->get('/clientes')
        ->assertForbidden();
});

it('barra quem so consulta de cadastrar', function () {
    // Expedição vê venda (`sales.view`) e não cria (`sales.create`).
    actingAs(vendedor(Role::Fulfillment))
        ->post('/clientes', dadosDeCliente())
        ->assertForbidden();
});
