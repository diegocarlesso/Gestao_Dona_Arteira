<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;
use App\Modules\Sales\Models\IbgeMunicipality;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\BuildOrderInvoiceSnapshot;
use App\Modules\Sales\Services\ResolveIbgeCityCode;
use Database\Seeders\Identity\RolePermissionSeeder;
use Database\Seeders\Sales\IbgeMunicipalitySeeder;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Resolução do código IBGE do município — BR-607, ADR-0026
|--------------------------------------------------------------------------
|
| A regra que molda tudo aqui: **não aproximar**. Um código errado produz
| NF-e autorizada com o município do destinatário incorreto — falha que
| passa por todos os controles e ninguém percebe. Nulo, ao contrário, vira
| pendência na tela e alguém resolve escolhendo o município à mão.
|
| Daí a suíte testar tanto o que casa quanto, com o mesmo peso, o que se
| recusa a casar.
|
*/

beforeEach(function () {
    $this->seed(IbgeMunicipalitySeeder::class);
    $this->seed(RolePermissionSeeder::class);
});

function resolvedor(): ResolveIbgeCityCode
{
    return app(ResolveIbgeCityCode::class);
}

/**
 * Helpers próprios, em vez de reusar os de `ClientesTest`.
 *
 * Funções de teste em Pest são globais, mas só existem depois que o
 * arquivo que as declara é carregado — reusá-las faria este arquivo passar
 * na suíte inteira e quebrar ao rodar sozinho. `PedidosTest` já tomou a
 * mesma decisão (`vendedorDe`).
 */
function operadorIbge(Role $papel = Role::Sales): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum($papel);
}

/**
 * @param  list<array<string, mixed>>  $enderecos
 * @return array<string, mixed>
 */
function clienteComEnderecos(array $enderecos): array
{
    return [
        'type' => 'individual',
        'name' => 'Maria de Souza',
        'is_wholesale' => false,
        'enderecos' => $enderecos,
    ];
}

// ------------------------------------------------- o casamento que acerta

it('resolve o municipio por cidade e UF', function (string $cidade, string $uf, string $codigo) {
    expect(resolvedor()->resolver($cidade, $uf))->toBe($codigo);
})->with([
    'grafia oficial' => ['Porto Alegre', 'RS', '4314902'],
    'com acento' => ['São Paulo', 'SP', '3550308'],
    'caixa baixa' => ['são paulo', 'sp', '3550308'],
    'caixa alta sem acento' => ['SAO PAULO', 'SP', '3550308'],
    'espaço sobrando' => ['  Porto Alegre  ', ' RS ', '4314902'],
    'apóstrofo preservado' => ["Sant'Ana do Livramento", 'RS', '4317103'],
    'hífen preservado' => ['Embu-Guaçu', 'SP', '3515103'],
]);

it('nunca resolve sem a UF, porque existem homonimos', function () {
    // Duas Jacutingas: MG e RS. A do RS é a cidade da própria loja, e a
    // maioria dos clientes mora no entorno dela — casar só por nome
    // mandaria metade da base de clientes para Minas Gerais.
    expect(resolvedor()->resolver('Jacutinga', 'RS'))->toBe('4310900')
        ->and(resolvedor()->resolver('Jacutinga', 'MG'))->toBe('3134905')
        ->and(resolvedor()->resolver('Jacutinga', ''))->toBeNull()
        ->and(resolvedor()->resolver('Jacutinga', null))->toBeNull();
});

// ------------------------------------------- o casamento que se recusa

it('devolve nulo em vez de aproximar', function (string $cidade, string $uf) {
    expect(resolvedor()->resolver($cidade, $uf))->toBeNull();
})->with([
    // Nenhum destes é "quase Porto Alegre": ou é outra coisa, ou é uma
    // grafia que o sistema não tem como saber que quis dizer aquilo. Todos
    // caem no fallback manual, e é o desenho funcionando.
    'erro de digitação' => ['Porto Alegri', 'RS'],
    'abreviação' => ['P. Alegre', 'RS'],
    'espaço duplicado' => ['Porto  Alegre', 'RS'],
    'cidade certa, UF errada' => ['Porto Alegre', 'SP'],
    'cidade que não existe' => ['Cidade Inventada', 'RS'],
    'UF que não existe' => ['Porto Alegre', 'XX'],
    'cidade vazia' => ['', 'RS'],
    'só espaços' => ['   ', 'RS'],
]);

it('nao cai para o primeiro parecido quando ha varios com o mesmo comeco', function () {
    // "São" começa dezenas de municípios em SP. Um resolvedor que fizesse
    // LIKE devolveria o primeiro; este devolve nada.
    expect(resolvedor()->resolver('São', 'SP'))->toBeNull();
});

// ------------------------------------ o normalizador PHP × a coluna gerada

it('normaliza exatamente como a coluna gerada do banco', function () {
    // A garantia que sustenta o casamento inteiro: se as duas pontas
    // divergirem em um caractere, a resolução para de achar aquele
    // município — em silêncio, sem erro nenhum.
    //
    // O `IbgeMunicipalitySeederTest` já compara as 5.571 linhas contra uma
    // função de teste; aqui a comparação é contra o Service de verdade, que
    // é quem roda em produção.
    $divergentes = IbgeMunicipality::query()
        ->get(['name', 'name_normalized'])
        ->filter(fn (IbgeMunicipality $m): bool => $m->name_normalized !== resolvedor()->normalizar($m->name))
        ->map(fn (IbgeMunicipality $m): string => "{$m->name}: banco[{$m->name_normalized}] php[".resolvedor()->normalizar($m->name).']')
        ->all();

    expect($divergentes)->toBe([]);
});

it('acha todo municipio do brasil pela propria grafia oficial', function () {
    // O teste acima prova que as duas normalizações são iguais; este prova
    // que a consulta que o Service monta encontra a linha — as 5.571, uma
    // por uma. Junto, é a garantia de que nenhum cliente do país cai no
    // fallback manual por defeito da resolução.
    $naoAchados = IbgeMunicipality::query()
        ->get(['ibge_code', 'uf', 'name'])
        ->filter(fn (IbgeMunicipality $m): bool => resolvedor()->resolver($m->name, $m->uf) !== $m->ibge_code)
        ->map(fn (IbgeMunicipality $m): string => "{$m->name}/{$m->uf}")
        ->all();

    expect($naoAchados)->toBe([]);
})->group('lento');

// ---------------------------------------------- a busca da escolha manual

it('busca municipios da UF pelo trecho do nome', function () {
    $achados = resolvedor()->buscar('RS', 'jacu');

    expect(collect($achados)->pluck('codigo'))->toContain('4310900')
        // A busca é presa à UF: a Jacutinga de Minas não aparece numa
        // busca do Rio Grande do Sul.
        ->and(collect($achados)->pluck('codigo'))->not->toContain('3134905');
});

it('busca ignorando acento e caixa', function () {
    expect(collect(resolvedor()->buscar('SP', 'sao paulo'))->pluck('nome'))->toContain('São Paulo');
});

it('nao busca sem UF nem com termo curto demais', function () {
    expect(resolvedor()->buscar('', 'porto'))->toBe([])
        ->and(resolvedor()->buscar('RS', 'p'))->toBe([]);
});

it('trata curinga de LIKE como texto digitado', function () {
    // Sem escapar, "%" varreria a UF inteira e a tela ofereceria 497
    // municípios como se fossem resultado de busca.
    expect(resolvedor()->buscar('RS', '%%'))->toBe([]);
});

it('expoe a busca de municipios na rota, so para quem pode mexer no cadastro', function () {
    actingAs(operadorIbge())
        ->getJson('/municipios/buscar?uf=RS&q=jacu')
        ->assertOk()
        ->assertJsonFragment(['codigo' => '4310900', 'nome' => 'Jacutinga', 'uf' => 'RS']);

    // Quem não cadastra cliente não busca município.
    actingAs(operadorIbge(Role::Production))
        ->getJson('/municipios/buscar?uf=RS&q=jacu')
        ->assertForbidden();
});

// ----------------------------------------------- resolução ao salvar

it('resolve o municipio sozinho ao cadastrar o endereco', function () {
    actingAs(operadorIbge())
        ->post('/clientes', clienteComEnderecos([[
            'zip' => '95340000', 'street' => 'Rua das Flores', 'number' => '10',
            'city' => 'jacutinga', 'state' => 'rs',
        ]]))
        ->assertRedirect();

    // Cidade em caixa baixa, UF em caixa baixa, e ainda assim casa — e
    // casa com a Jacutinga do RS, não com a de Minas.
    expect(CustomerAddress::query()->value('city_code'))->toBe('4310900');
});

it('deixa nulo quando a grafia nao bate, em vez de chutar', function () {
    actingAs(operadorIbge())
        ->post('/clientes', clienteComEnderecos([[
            'zip' => '95340000', 'street' => 'Rua das Flores',
            'city' => 'Jacutinga do Sul', 'state' => 'RS',
        ]]))
        ->assertRedirect();

    expect(CustomerAddress::query()->value('city_code'))->toBeNull();
});

it('respeita o municipio escolhido a mao, sem tentar resolver por cima', function () {
    // O caso que o fallback existe para atender: a cidade está gravada com
    // grafia que a tabela não tem, e alguém escolheu o município na tela.
    // A resolução automática não pode desfazer essa escolha.
    actingAs(operadorIbge())
        ->post('/clientes', clienteComEnderecos([[
            'zip' => '95340000', 'street' => 'Rua das Flores',
            'city' => 'Jacutinga do Sul', 'state' => 'RS',
            'city_code' => '4310900',
        ]]))
        ->assertRedirect();

    expect(CustomerAddress::query()->value('city_code'))->toBe('4310900');
});

it('recusa um codigo que nao existe na tabela do IBGE, com erro de formulario', function () {
    // A FK barraria com QueryException (erro 500). A validação transforma
    // isso em mensagem no formulário — mesma recusa, resposta educada.
    actingAs(operadorIbge())
        ->post('/clientes', clienteComEnderecos([[
            'zip' => '95340000', 'street' => 'Rua das Flores',
            'city' => 'Jacutinga', 'state' => 'RS',
            'city_code' => '0000000',
        ]]))
        ->assertSessionHasErrors('enderecos.0.city_code');

    expect(Customer::query()->count())->toBe(0);
});

it('reresolve o municipio quando a cidade do endereco e corrigida', function () {
    actingAs(operadorIbge())
        ->post('/clientes', clienteComEnderecos([[
            'zip' => '95340000', 'street' => 'Rua das Flores',
            'city' => 'Jacutingaa', 'state' => 'RS',
        ]]))
        ->assertRedirect();

    $cliente = Customer::query()->firstOrFail();
    $endereco = $cliente->addresses()->firstOrFail();

    expect($endereco->city_code)->toBeNull();

    actingAs(operadorIbge())
        ->put("/clientes/{$cliente->public_id}", clienteComEnderecos([[
            'public_id' => $endereco->public_id,
            'zip' => '95340000', 'street' => 'Rua das Flores',
            'city' => 'Jacutinga', 'state' => 'RS',
        ]]))
        ->assertRedirect();

    expect($endereco->refresh()->city_code)->toBe('4310900');
});

// ------------------------------------------------------ pendência na tela

it('mostra a falta do codigo IBGE como pendencia do cliente', function () {
    $cliente = Customer::factory()->create(['doc' => '52998224725']);
    CustomerAddress::factory()->for($cliente)->create(['city' => 'Cidade Inventada', 'state' => 'RS']);

    expect($cliente->pendencias())->toBe(['sem código IBGE do município']);

    $cliente->addresses()->update(['city_code' => '4310900']);

    expect($cliente->refresh()->pendencias())->toBe([]);
});

it('nao conta a mesma lacuna duas vezes para quem nao tem endereco', function () {
    $cliente = Customer::factory()->create(['doc' => '52998224725']);

    expect($cliente->pendencias())->toBe(['sem endereço']);
});

it('filtra a listagem por quem esta sem codigo IBGE', function () {
    $pendente = Customer::factory()->create(['name' => 'Cliente Pendente']);
    CustomerAddress::factory()->for($pendente)->create(['city' => 'Cidade Inventada']);

    $resolvido = Customer::factory()->create(['name' => 'Cliente Resolvido']);
    CustomerAddress::factory()->for($resolvido)->comMunicipio()->create();

    actingAs(operadorIbge())
        ->get('/clientes?pendencia=sem_ibge')
        ->assertOk()
        ->assertSee('Cliente Pendente')
        ->assertDontSee('Cliente Resolvido');
});

// --------------------------------------------- o contrato com o Fiscal

it('entrega o codigo do municipio no snapshot da nota', function () {
    $cliente = Customer::factory()->create();
    CustomerAddress::factory()->for($cliente)->comMunicipio()->create();

    $pedido = Order::factory()->create(['customer_id' => $cliente->id]);

    $snapshot = app(BuildOrderInvoiceSnapshot::class)->handle($pedido->id);

    // Era `null` fixo até o ADR-0026 — a lacuna que travava a emissão.
    expect($snapshot?->shippingAddress?->cityCode)->toBe('4314902');
});

it('mantem o codigo nulo no snapshot quando o endereco esta pendente', function () {
    $cliente = Customer::factory()->create();
    CustomerAddress::factory()->for($cliente)->create(['city' => 'Cidade Inventada']);

    $pedido = Order::factory()->create(['customer_id' => $cliente->id]);

    // Nulo continua sendo resposta válida: quem recusa a emissão é o
    // Fiscal, com mensagem própria (ADR-0025) — nunca um código chutado.
    expect(app(BuildOrderInvoiceSnapshot::class)->handle($pedido->id)?->shippingAddress?->cityCode)->toBeNull();
});

// ------------------------------------------------------------- backfill

it('resolve os enderecos antigos em lote, sem gravar antes de mandarem', function () {
    $cliente = Customer::factory()->create(['name' => 'Dona Arteira']);

    $resolve = CustomerAddress::factory()->for($cliente)->create(['city' => 'Jacutinga', 'state' => 'RS']);
    $naoResolve = CustomerAddress::factory()->for($cliente)->create(['city' => 'Jacutinga do Sul', 'state' => 'RS']);

    // O `saving` do model não mexe em `city_code`, então os dois nascem
    // nulos mesmo com cidade válida — é o estado do banco antes do backfill.
    DB::table('customer_addresses')->update(['city_code' => null]);

    // Simulação: relata, não grava.
    $this->artisan('erp:enderecos:resolver-ibge')
        ->expectsOutputToContain('Jacutinga do Sul/RS')
        ->assertSuccessful();

    expect($resolve->refresh()->city_code)->toBeNull();

    $this->artisan('erp:enderecos:resolver-ibge --gravar')->assertSuccessful();

    expect($resolve->refresh()->city_code)->toBe('4310900')
        // O que não casou continua nulo — o backfill também não aproxima.
        ->and($naoResolve->refresh()->city_code)->toBeNull();
});

it('nao faz nada quando ninguem esta pendente', function () {
    CustomerAddress::factory()->comMunicipio()->create();

    $this->artisan('erp:enderecos:resolver-ibge')
        ->expectsOutputToContain('Nenhum endereço sem código IBGE')
        ->assertSuccessful();
});
