<?php

declare(strict_types=1);

use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Integrations\WooCommerce\Models\StgWooCustomer;
use App\Modules\Integrations\WooCommerce\Services\LoadWooCustomers;
use App\Modules\Sales\Enums\CustomerOrigin;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;
use App\Modules\Sales\Services\ResolveCustomerService;

/*
|--------------------------------------------------------------------------
| Carga dos clientes do staging para Vendas — docs/17-Migracao F4
|--------------------------------------------------------------------------
|
| O que separa esta carga da do catálogo: **o destino já está povoado**.
| Desde o Gate 02, todo pedido do site que entra cria o cliente pelo
| `ResolveCustomerService` — sem endereço. A carga encontra três situações
| (mapeamento existe · e-mail/doc casa · ninguém), e em nenhuma delas pode
| duplicar. É o que estes testes provam, junto com a idempotência que a
| pasta 17 exige no princípio 1.
|
*/

const CPF_ANA = '52998224725';
const CPF_JOAO = '11144477735';

/** Uma linha já triada como cliente, pronta para carregar. */
function stgTriado(int $wooId, array $payload = [], array $colunas = []): StgWooCustomer
{
    $payload = array_merge([
        'id' => $wooId,
        'email' => "cliente{$wooId}@exemplo.test",
        'first_name' => 'Maria',
        'last_name' => 'Silva',
        'billing' => [
            'first_name' => 'Maria',
            'last_name' => 'Silva',
            'address_1' => 'Rua das Flores',
            'address_2' => 'Apto 302',
            'city' => 'Jacutinga',
            'state' => 'RS',
            'postcode' => '99570-000',
            'phone' => '(54) 99999-1234',
        ],
        'meta_data' => [
            ['key' => '_billing_number', 'value' => '123'],
            ['key' => '_billing_neighborhood', 'value' => 'Centro'],
        ],
    ], $payload);

    return StgWooCustomer::query()->create(array_merge([
        'woo_id' => $wooId,
        'email' => isset($payload['email']) ? mb_strtolower((string) $payload['email']) : null,
        'first_name' => $payload['first_name'] ?: null,
        'last_name' => $payload['last_name'] ?: null,
        'orders_count' => 1,
        'payload' => $payload,
        'status_triagem' => 'cliente',
        'nome_proposto' => trim(((string) $payload['first_name']).' '.((string) $payload['last_name'])) ?: 'Cliente do site',
        'doc_proposto' => null,
    ], $colunas));
}

it('cria o cliente com origem do site e sem marca de atacado', function () {
    stgTriado(10, colunas: ['doc_proposto' => CPF_ANA]);

    app(LoadWooCustomers::class)->executar();

    $cliente = Customer::query()->first();

    // A pasta 31 §3 mediu 85 pedidos, 100% pessoa física, zero CNPJ: o
    // atacado acontece fora do site e não tem fonte de dados nenhuma.
    expect($cliente->name)->toBe('Maria Silva')
        ->and($cliente->origin)->toBe(CustomerOrigin::Woo)
        ->and($cliente->doc)->toBe(CPF_ANA)
        ->and($cliente->phone)->toBe('(54) 99999-1234')
        ->and($cliente->is_wholesale)->toBeFalse();
});

it('grava o endereço que o pedido do site nunca gravou', function () {
    stgTriado(10);

    app(LoadWooCustomers::class)->executar();

    $endereco = CustomerAddress::query()->first();

    expect($endereco->street)->toBe('Rua das Flores')
        // Número e bairro vêm do plugin brasileiro, não de `address_1`.
        ->and($endereco->number)->toBe('123')
        ->and($endereco->district)->toBe('Centro')
        ->and($endereco->complement)->toBe('Apto 302')
        ->and($endereco->zip)->toBe('99570000')
        ->and($endereco->city)->toBe('Jacutinga')
        ->and($endereco->state)->toBe('RS');
});

it('junta cobrança e entrega iguais num registro só, com os dois selos', function () {
    $mesmo = [
        'address_1' => 'Rua das Flores',
        'city' => 'Jacutinga',
        'state' => 'RS',
        'postcode' => '99570-000',
    ];

    stgTriado(10, [
        'billing' => $mesmo + ['phone' => '(54) 99999-1234'],
        'shipping' => $mesmo,
        // O plugin brasileiro guarda o número dos dois lados; sem o de
        // entrega, os endereços teriam números diferentes (um "123", outro
        // vazio) e não seriam o mesmo endereço.
        'meta_data' => [
            ['key' => '_billing_number', 'value' => '123'],
            ['key' => '_shipping_number', 'value' => '123'],
        ],
    ]);

    app(LoadWooCustomers::class)->executar();

    // Dois registros gêmeos fariam a tela de venda pedir uma escolha sem
    // conteúdo entre duas linhas iguais.
    expect(CustomerAddress::query()->count())->toBe(1);

    $endereco = CustomerAddress::query()->first();

    expect($endereco->is_default_billing)->toBeTrue()
        ->and($endereco->is_default_shipping)->toBeTrue();
});

it('grava dois registros quando a entrega é noutro endereço', function () {
    stgTriado(10, ['shipping' => [
        'address_1' => 'Av. Brasil',
        'city' => 'Porto Alegre',
        'state' => 'RS',
        'postcode' => '90000-000',
    ]]);

    app(LoadWooCustomers::class)->executar();

    expect(CustomerAddress::query()->count())->toBe(2)
        ->and(CustomerAddress::query()->where('is_default_shipping', true)->value('city'))->toBe('Porto Alegre')
        ->and(CustomerAddress::query()->where('is_default_billing', true)->value('city'))->toBe('Jacutinga');
});

it('não transforma endereço incompleto em registro em branco', function () {
    stgTriado(10, ['billing' => [
        'address_1' => 'Rua sem cidade',
        'city' => '',
        'state' => 'RS',
        'phone' => '(54) 99999-1234',
    ]]);

    $r = app(LoadWooCustomers::class)->executar();

    // Sem cidade não cota frete, não vira destinatário de NF-e e ocuparia a
    // tela fingindo ser um dado. O cliente entra assim mesmo, com a
    // pendência visível.
    expect(CustomerAddress::query()->count())->toBe(0)
        ->and($r['enderecos_recusados'][0])->toContain('endereço de cobrança incompleto')
        ->and(Customer::query()->first()->pendencias())->toContain('sem endereço');
});

it('não trata `shipping` vazio como recusa — é o normal do Woo', function () {
    stgTriado(10, ['shipping' => ['address_1' => '', 'city' => '', 'state' => '']]);

    $r = app(LoadWooCustomers::class)->executar();

    // O site deixa `shipping` em branco quando o comprador entrega no
    // endereço de cobrança. Reportar isso encheria o relatório de ruído e
    // esconderia as recusas de verdade.
    expect($r['enderecos_recusados'])->toBe([])
        ->and(CustomerAddress::query()->count())->toBe(1);
});

it('enriquece o cliente que o pedido do site já criara, sem criar um segundo', function () {
    // Exatamente o que o `ImportWooOrder` faz ao importar um pedido: cria o
    // cliente pela superfície de Vendas e mapeia o id do Woo.
    $existente = app(ResolveCustomerService::class)->paraCanal(
        name: null,
        email: 'cliente10@exemplo.test',
        doc: null,
    );
    IntegrationMapping::registrar('customer', $existente, 10);

    stgTriado(10, colunas: ['doc_proposto' => CPF_ANA]);

    $r = app(LoadWooCustomers::class)->executar();

    $cliente = Customer::query()->first();

    expect(Customer::query()->count())->toBe(1)
        ->and($r['enriquecidos'])->toBe(1)
        ->and($r['criados'])->toBe(0)
        // O nome-recuo é a marca de "não sabemos": preservá-lo diante do
        // nome real seria preservar a lacuna, não uma decisão.
        ->and($cliente->name)->toBe('Maria Silva')
        ->and($cliente->doc)->toBe(CPF_ANA)
        ->and($cliente->addresses()->count())->toBe(1);
});

it('preserva o valor que o ERP já tinha, e reporta a diferença', function () {
    $existente = app(ResolveCustomerService::class)->paraCanal(
        name: 'Maria S. Corrigida à mão',
        email: 'cliente10@exemplo.test',
        doc: null,
        phone: '(54) 3333-0000',
    );
    IntegrationMapping::registrar('customer', $existente, 10);

    stgTriado(10);

    $r = app(LoadWooCustomers::class)->executar();

    // Regra 5 do projeto: conflito resolve-se a favor do ERP. A base de
    // clientes não nasceu vazia, e alguém pode ter corrigido isso à mão.
    expect(Customer::query()->first()->name)->toBe('Maria S. Corrigida à mão')
        ->and($r['conflitos'])->toHaveCount(2)
        ->and(implode(' ', $r['conflitos']))->toContain('nome: ERP');
});

it('mescla com o cliente cadastrado à mão quando o e-mail bate', function () {
    $manual = Customer::factory()->create(['email' => 'ana@exemplo.test', 'name' => 'Ana do balcão']);

    stgTriado(10, ['email' => 'ana@exemplo.test']);

    $r = app(LoadWooCustomers::class)->executar();

    // Dois cadastros viraram um: o mapeamento aponta para o que já existia,
    // e o merge aparece no relatório porque a operação precisa ver isso.
    expect(Customer::query()->count())->toBe(1)
        ->and($r['mesclados'])->toBe(1)
        ->and(IntegrationMapping::localDe('customer', 10))->toBe($manual->id)
        ->and($r['merges'][0])->toContain('Woo #10');
});

it('mescla pelo documento válido, mesmo com o e-mail de outra pessoa', function () {
    $manual = Customer::factory()->create(['email' => 'outro@exemplo.test', 'doc' => CPF_ANA]);

    stgTriado(10, ['email' => 'novo@exemplo.test'], ['doc_proposto' => CPF_ANA]);

    app(LoadWooCustomers::class)->executar();

    // O CPF identifica a pessoa; o e-mail identifica uma caixa postal, que
    // casal e família compartilham (regra da pasta 17 F3).
    expect(Customer::query()->count())->toBe(1)
        ->and(IntegrationMapping::localDe('customer', 10))->toBe($manual->id);
});

it('migra sem documento quando o número já pertence a outro cliente', function () {
    Customer::factory()->create(['email' => 'outro@exemplo.test', 'doc' => CPF_ANA]);

    // Cadastro do site com o mesmo CPF, mas e-mail diferente: o `doc` é
    // único no banco, então gravá-lo estouraria a carga inteira.
    stgTriado(10, ['email' => 'novo@exemplo.test'], ['doc_proposto' => CPF_ANA]);

    // O `idPorEmailOuDoc` casaria pelo documento, então força o caso raro:
    // o mapeamento já aponta para um terceiro cliente.
    $terceiro = Customer::factory()->create(['email' => 'terceiro@exemplo.test']);
    IntegrationMapping::registrar('customer', $terceiro->id, 10);

    $r = app(LoadWooCustomers::class)->executar();

    expect($terceiro->refresh()->doc)->toBeNull()
        ->and($r['documentos_recusados'][0])->toContain('já pertence ao cliente local')
        ->and($terceiro->pendencias())->toContain('sem CPF/CNPJ');
});

it('não duplica ao carregar duas vezes', function () {
    stgTriado(10, colunas: ['doc_proposto' => CPF_ANA]);
    stgTriado(20, ['email' => 'joao@exemplo.test', 'first_name' => 'João', 'last_name' => 'Souza'], ['doc_proposto' => CPF_JOAO]);

    $carga = app(LoadWooCustomers::class);
    $primeira = $carga->executar();
    $segunda = $carga->executar();

    // BR-706 pelo `integration_mappings`. Na segunda rodada tudo é
    // enriquecimento e nenhum endereço passa a existir — é assim que a
    // idempotência aparece no relatório, e não só na contagem de linhas.
    expect(Customer::query()->count())->toBe(2)
        ->and(CustomerAddress::query()->count())->toBe(2)
        ->and($primeira['criados'])->toBe(2)
        ->and($segunda['criados'])->toBe(0)
        ->and($segunda['enriquecidos'])->toBe(2)
        ->and($segunda['enderecos_novos'])->toBe(0)
        ->and($segunda['total_mapeado'])->toBe($primeira['total_mapeado']);
});

it('não duplica quando o pedido do site já criou o cliente antes da migração', function () {
    $existente = app(ResolveCustomerService::class)->paraCanal(
        name: null,
        email: 'cliente10@exemplo.test',
        doc: null,
    );
    IntegrationMapping::registrar('customer', $existente, 10);

    stgTriado(10);

    $carga = app(LoadWooCustomers::class);
    $carga->executar();
    $carga->executar();

    // O cenário real do dia da carga: o ERP já vinha recebendo pedidos.
    expect(Customer::query()->count())->toBe(1)
        ->and(CustomerAddress::query()->count())->toBe(1);
});

it('grava o mapeamento com o id do Woo', function () {
    stgTriado(10);

    app(LoadWooCustomers::class)->executar();

    $localId = Customer::query()->value('id');

    // BR-704: sem o mapeamento, a sincronização pós-cutover criaria um
    // segundo cliente a cada webhook em vez de atualizar o primeiro.
    expect($localId)->not->toBeNull()
        ->and(IntegrationMapping::localDe('customer', 10))->toBe($localId);
});

it('ignora quem a triagem não classificou como cliente', function () {
    stgTriado(10, colunas: ['status_triagem' => 'sem_pedido']);
    stgTriado(20, colunas: ['status_triagem' => 'duplicado']);
    stgTriado(30, colunas: ['status_triagem' => 'pending']);

    app(LoadWooCustomers::class)->executar();

    expect(Customer::query()->count())->toBe(0);
});

it('não grava nada em dry-run, mas conta o que faria', function () {
    stgTriado(10, colunas: ['doc_proposto' => CPF_ANA]);
    stgTriado(20, ['email' => 'joao@exemplo.test'], ['doc_proposto' => CPF_JOAO]);

    $r = app(LoadWooCustomers::class)->executar(dryRun: true);

    // É este relatório que o dono lê antes de autorizar a entrada de dado
    // pessoal de gente de verdade — ele precisa ser verdadeiro.
    expect(Customer::query()->count())->toBe(0)
        ->and(CustomerAddress::query()->count())->toBe(0)
        ->and(IntegrationMapping::query()->count())->toBe(0)
        ->and($r['criados'])->toBe(2)
        ->and($r['enderecos_novos'])->toBe(2);
});

it('a previsão do dry-run bate com o que a carga faz', function () {
    app(ResolveCustomerService::class)->paraCanal(name: null, email: 'cliente10@exemplo.test', doc: null);

    stgTriado(10);
    stgTriado(20, ['email' => 'joao@exemplo.test']);

    $carga = app(LoadWooCustomers::class);
    $previsto = $carga->executar(dryRun: true);
    $feito = $carga->executar();

    // Um dry-run que reimplementasse as regras prometeria uma coisa e faria
    // outra no dia seguinte. Os dois passam pelo mesmo caminho de decisão.
    foreach (['criados', 'enriquecidos', 'mesclados', 'enderecos_novos', 'sem_documento', 'sem_endereco'] as $campo) {
        expect($feito[$campo])->toBe($previsto[$campo], "campo {$campo}");
    }
});

it('o comando avisa que nada foi gravado no dry-run', function () {
    stgTriado(10, colunas: ['doc_proposto' => CPF_ANA]);

    $this->artisan('erp:migrate:load clientes --dry-run')
        ->expectsOutputToContain('Nada foi gravado')
        ->assertSuccessful();

    expect(Customer::query()->count())->toBe(0);
});

it('o comando recusa carregar antes da triagem', function () {
    $this->artisan('erp:migrate:load clientes')
        ->expectsOutputToContain('Nenhum cliente triado')
        ->assertFailed();
});

it('o comando sem argumento continua sendo o do catálogo', function () {
    stgTriado(10);

    // A doc e a memória da operação registram `erp:migrate:load` sem
    // argumento como a carga do catálogo. Mudar isso quebraria o runbook.
    $this->artisan('erp:migrate:load')
        ->expectsOutputToContain('Nada aprovado')
        ->assertFailed();

    expect(Customer::query()->count())->toBe(0);
});

it('conta as pendências que entram junto, sem bloquear ninguém', function () {
    stgTriado(10, colunas: ['doc_proposto' => null]);
    stgTriado(20, ['email' => 'b@exemplo.test'], ['doc_proposto' => '11111111111']);

    $r = app(LoadWooCustomers::class)->executar();

    // Decisão da pasta 17 F3: documento ausente ou inválido não recusa o
    // cliente — vira pendência fiscal, que barra a NF-e e não a migração.
    expect(Customer::query()->count())->toBe(2)
        ->and($r['sem_documento'])->toBe(1)
        ->and($r['documento_invalido'])->toBe(1);
});
