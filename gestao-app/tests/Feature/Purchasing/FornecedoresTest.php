<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Purchasing\Models\Supplier;
use Database\Seeders\Identity\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Fornecedores — docs/11-Compras §4 (Fase 1 do P0.1)
|--------------------------------------------------------------------------
|
| O que o cadastro promete: CNPJ válido e único, prazos guardados em dias
| (o de pagamento é o que decide o vencimento do título — BR-402), e
| inativação que não apaga histórico de compra.
|
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/** Só o Admin tem `purchasing.manage` na matriz da pasta 19. */
function compradorDe(Role $papel = Role::Admin): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum($papel);
}

/**
 * @param  array<string, mixed>  $campos
 * @return array<string, mixed>
 */
function dadosDeFornecedor(array $campos = []): array
{
    return array_merge([
        'legal_name' => 'Gesso Bom Comércio Ltda',
        'trade_name' => 'Gesso do Zé',
        'document' => '11.222.333/0001-81',
        'email' => 'contato@gessobom.com.br',
        'phone' => '(41) 99999-0000',
        'payment_terms_days' => 30,
        'lead_time_days' => 15,
        'notes' => null,
    ], $campos);
}

// ------------------------------------------------------- documento

it('recusa fornecedor com CNPJ inválido', function () {
    actingAs(compradorDe())
        ->post('/fornecedores', dadosDeFornecedor(['document' => '11.222.333/0001-99']))
        ->assertSessionHasErrors('document');

    expect(Supplier::query()->count())->toBe(0);
});

it('exige documento — comprar de quem não se sabe quem é deixa a conta sem dono', function () {
    actingAs(compradorDe())
        ->post('/fornecedores', dadosDeFornecedor(['document' => '']))
        ->assertSessionHasErrors('document');
});

it('guarda o documento só com dígitos, venha como vier', function () {
    actingAs(compradorDe())->post('/fornecedores', dadosDeFornecedor())->assertRedirect();

    $fornecedor = Supplier::query()->firstOrFail();

    expect($fornecedor->document)->toBe('11222333000181')
        ->and($fornecedor->documentoFormatado())->toBe('11.222.333/0001-81');
});

it('recusa o mesmo CNPJ duas vezes, mesmo mascarado diferente', function () {
    actingAs(compradorDe())->post('/fornecedores', dadosDeFornecedor())->assertRedirect();

    actingAs(compradorDe())
        ->post('/fornecedores', dadosDeFornecedor(['document' => '11222333000181']))
        ->assertSessionHasErrors('document');

    expect(Supplier::query()->count())->toBe(1);
});

it('aceita fornecedor pessoa física (o artesão que fornece a peça crua)', function () {
    actingAs(compradorDe())
        ->post('/fornecedores', dadosDeFornecedor(['document' => '123.456.789-09']))
        ->assertRedirect();

    expect(Supplier::query()->value('document'))->toBe('12345678909');
});

// ---------------------------------------------------------- prazos

it('recusa prazo absurdo — "3000" é erro de digitação de "30"', function (string $campo) {
    actingAs(compradorDe())
        ->post('/fornecedores', dadosDeFornecedor([$campo => 3000]))
        ->assertSessionHasErrors($campo);
})->with(['payment_terms_days', 'lead_time_days']);

it('calcula o vencimento a partir da emissão e do prazo do fornecedor (BR-402)', function () {
    $fornecedor = Supplier::factory()->comPrazo(28)->create();

    expect($fornecedor->vencimentoAPartirDe(Carbon::parse('2026-08-10')))
        ->toBe('2026-09-07');
});

it('devolve vencimento nulo quando o prazo não está cadastrado', function () {
    // Título sem vencimento continua devido e visível no aberto; um
    // vencimento inventado entraria mudo no caixa projetado.
    $fornecedor = Supplier::factory()->semPrazoDePagamento()->create();

    expect($fornecedor->vencimentoAPartirDe(now()))->toBeNull();
});

// ------------------------------------------------------ inativação

it('inativa sem apagar — o histórico de compra continua de pé', function () {
    $fornecedor = Supplier::factory()->create();

    actingAs(compradorDe())
        ->delete("/fornecedores/{$fornecedor->public_id}")
        ->assertRedirect('/fornecedores');

    expect(Supplier::query()->count())->toBe(0)
        ->and(Supplier::withTrashed()->count())->toBe(1);
});

// ----------------------------------------------------------- telas

it('lista os fornecedores cadastrados', function () {
    Supplier::factory()->create(['legal_name' => 'Tintas Colorir Ltda']);

    actingAs(compradorDe())
        ->get('/fornecedores')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/suppliers/index')
            ->where('fornecedores.data.0.legal_name', 'Tintas Colorir Ltda')
        );
});

it('encontra o fornecedor pelo nome fantasia, que é como a operação o chama', function () {
    Supplier::factory()->create(['legal_name' => 'Comércio de Artigos Ltda ME', 'trade_name' => 'Gesso do Zé']);

    actingAs(compradorDe())
        ->getJson('/fornecedores/buscar?q=Gesso do')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.nome', 'Gesso do Zé');
});

// ------------------------------------------------------ permissões

it('barra quem não pode gerenciar compras', function (string $rota) {
    actingAs(compradorDe(Role::Sales))
        ->get($rota)
        ->assertForbidden();
})->with(['/fornecedores', '/fornecedores/novo']);

it('barra quem não pode gerenciar compras de cadastrar fornecedor', function () {
    actingAs(compradorDe(Role::Finance))
        ->post('/fornecedores', dadosDeFornecedor())
        ->assertForbidden();

    expect(Supplier::query()->count())->toBe(0);
});
