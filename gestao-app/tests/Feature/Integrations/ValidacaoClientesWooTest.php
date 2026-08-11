<?php

declare(strict_types=1);

use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Integrations\WooCommerce\Models\StgWooCustomer;
use App\Modules\Integrations\WooCommerce\Services\LoadWooCustomers;
use App\Modules\Integrations\WooCommerce\Services\ValidateWooCustomers;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;
use App\Modules\Sales\Services\ResolveCustomerService;

/*
|--------------------------------------------------------------------------
| Validação da migração de clientes — docs/17-Migracao F5
|--------------------------------------------------------------------------
|
| A conferência que o dono assina. Ela precisa provar duas coisas opostas:
| que dá verde quando a carga foi fiel, e que ACUSA quando não foi — senão
| é um verde decorativo. E precisa separar erro de diferença preservada:
| a carga deixa o valor do ERP vencer o do site (regra 5 do projeto), e
| chamar isso de divergência seria acusar a carga de errar onde acertou.
|
*/

/**
 * Uma linha triada, pronta para carregar e depois validar.
 *
 * O documento é declarado **no payload**, como a origem o traz, e a coluna
 * `doc_proposto` é derivada dele — a validação relê o payload por conta
 * própria, e um fixture que só preenchesse a coluna testaria a carga
 * contra o vazio.
 */
function stgParaValidar(int $wooId, array $payload = [], array $colunas = []): StgWooCustomer
{
    $payload = array_merge([
        'id' => $wooId,
        'email' => "cliente{$wooId}@exemplo.test",
        'first_name' => 'Maria',
        'last_name' => 'Silva',
        'billing' => [
            'address_1' => 'Rua das Flores',
            'city' => 'Jacutinga',
            'state' => 'RS',
            'postcode' => '99570-000',
            'phone' => '(54) 99999-1234',
        ],
        'meta_data' => [['key' => '_billing_number', 'value' => '123']],
    ], $payload);

    $cpf = null;

    foreach ($payload['meta_data'] ?? [] as $meta) {
        if (($meta['key'] ?? '') === '_billing_cpf') {
            $cpf = preg_replace('/\D/', '', (string) $meta['value']);
        }
    }

    return StgWooCustomer::query()->create(array_merge([
        'woo_id' => $wooId,
        'email' => isset($payload['email']) ? mb_strtolower((string) $payload['email']) : null,
        'first_name' => $payload['first_name'] ?: null,
        'last_name' => $payload['last_name'] ?: null,
        'orders_count' => 1,
        'payload' => $payload,
        'status_triagem' => 'cliente',
        'nome_proposto' => trim(((string) $payload['first_name']).' '.((string) $payload['last_name'])) ?: 'Cliente do site',
        'doc_proposto' => $cpf,
    ], $colunas));
}

/** O bloco de metadados com CPF, do jeito que o plugin brasileiro grava. */
function metaComCpf(string $cpf): array
{
    return ['meta_data' => [
        ['key' => '_billing_number', 'value' => '123'],
        ['key' => '_billing_cpf', 'value' => $cpf],
    ]];
}

it('confirma que o cadastro reflete a origem quando a carga foi fiel', function () {
    stgParaValidar(10, metaComCpf('529.982.247-25'));

    app(LoadWooCustomers::class)->executar();

    $r = app(ValidateWooCustomers::class)->executar();

    expect($r['contagens']['bate'])->toBeTrue()
        ->and($r['contagens']['triados'])->toBe(1)
        ->and($r['contagens']['mapeados'])->toBe(1)
        ->and($r['divergentes'])->toBe(0)
        ->and($r['amostra'][0]['divergencias'])->toBe([])
        ->and($r['amostra'][0]['preservados'])->toBe([]);
});

it('acusa contagem que não bate', function () {
    stgParaValidar(10);
    app(LoadWooCustomers::class)->executar();

    // Um segundo triado que ninguém carregou: 2 triados, 1 mapeado.
    stgParaValidar(20, ['email' => 'outro@exemplo.test']);

    $r = app(ValidateWooCustomers::class)->executar();

    expect($r['contagens']['triados'])->toBe(2)
        ->and($r['contagens']['mapeados'])->toBe(1)
        ->and($r['contagens']['bate'])->toBeFalse();
});

it('acusa cliente que não chegou ao cadastro', function () {
    stgParaValidar(10);

    $r = app(ValidateWooCustomers::class)->executar();

    expect($r['divergentes'])->toBe(1)
        ->and($r['amostra'][0]['divergencias'][0])->toContain('sem mapeamento');
});

it('acusa endereço da origem que não está no cadastro', function () {
    stgParaValidar(10);
    app(LoadWooCustomers::class)->executar();

    // Alguém apagou o endereço depois da carga — ou a carga nunca o gravou.
    CustomerAddress::query()->delete();

    $r = app(ValidateWooCustomers::class)->executar();

    expect($r['amostra'][0]['divergencias'][0])->toContain('endereço de cobrança não está no cadastro');
});

it('acusa lacuna que a carga deveria ter preenchido', function () {
    stgParaValidar(10, metaComCpf('529.982.247-25'));
    app(LoadWooCustomers::class)->executar();

    // A origem tinha o documento e o cadastro ficou sem: ou a carga perdeu
    // o dado, ou alguém o apagou. Nos dois casos é divergência.
    Customer::query()->update(['doc' => null]);

    $r = app(ValidateWooCustomers::class)->executar();

    expect($r['amostra'][0]['divergencias'][0])->toContain('documento');
});

it('chama de preservada a diferença em que o ERP venceu', function () {
    // O pedido do site criou o cliente e alguém corrigiu o nome à mão.
    app(ResolveCustomerService::class)->paraCanal(
        name: 'Maria S. Corrigida à mão',
        email: 'cliente10@exemplo.test',
        doc: null,
    );

    stgParaValidar(10);
    app(LoadWooCustomers::class)->executar();

    $r = app(ValidateWooCustomers::class)->executar();

    // Não é erro: é a regra 5 do projeto funcionando. Acusar divergência
    // aqui seria culpar a carga por acertar.
    expect($r['divergentes'])->toBe(0)
        ->and($r['amostra'][0]['preservados'][0])->toContain('nome: ERP');
});

it('aceita documento em branco quando o número pertence a outro cliente', function () {
    Customer::factory()->create(['email' => 'outro@exemplo.test', 'doc' => '52998224725']);

    $terceiro = Customer::factory()->create(['email' => 'terceiro@exemplo.test']);
    IntegrationMapping::registrar('customer', $terceiro->id, 10);

    stgParaValidar(10, ['email' => 'novo@exemplo.test'] + metaComCpf('529.982.247-25'));
    app(LoadWooCustomers::class)->executar();

    $r = app(ValidateWooCustomers::class)->executar();

    // A recusa é documentada pela carga, e a validação a reconfere por
    // conta própria: se ninguém mais tivesse o número, seria divergência.
    expect($r['divergentes'])->toBe(0)
        ->and(implode(' ', $r['amostra'][0]['preservados']))->toContain('recusado');
});

it('acusa cliente do site marcado como atacadista', function () {
    stgParaValidar(10);
    app(LoadWooCustomers::class)->executar();

    Customer::query()->update(['is_wholesale' => true]);

    $r = app(ValidateWooCustomers::class)->executar();

    // A pasta 31 §3 mediu 100% pessoa física no canal. Atacado no cliente
    // do site é dimensão que a origem não tem.
    expect($r['amostra'][0]['divergencias'][0])->toContain('atacadista');
});

it('conta os rejeitados junto, para a assinatura cobrir o descarte', function () {
    stgParaValidar(10);
    stgParaValidar(20, ['email' => 'b@exemplo.test'], ['status_triagem' => 'sem_pedido']);
    stgParaValidar(30, ['email' => 'c@exemplo.test'], ['status_triagem' => 'duplicado']);

    app(LoadWooCustomers::class)->executar();

    $r = app(ValidateWooCustomers::class)->executar();

    expect($r['rejeitados']['sem_pedido'])->toBe(1)
        ->and($r['rejeitados']['duplicado'])->toBe(1);
});

it('amostra de forma estável — a mesma rodada devolve as mesmas pessoas', function () {
    for ($i = 1; $i <= 40; $i++) {
        stgParaValidar(100 + $i, ['email' => "pessoa{$i}@exemplo.test"]);
    }
    app(LoadWooCustomers::class)->executar();

    $ids = fn (array $r): array => array_column($r['amostra'], 'woo_id');

    $primeira = app(ValidateWooCustomers::class)->executar(10);
    $segunda = app(ValidateWooCustomers::class)->executar(10);

    // Conferência assinada não pode depender de sorteio que muda.
    expect($ids($primeira))->toBe($ids($segunda))
        ->and($primeira['conferidos'])->toBe(10);
});

it('recusa validar banco vazio em vez de dar falso-verde', function () {
    // 0 triados = migração ausente, não fiel. O engano provável é rodar no
    // banco local em vez de produção.
    $this->artisan('erp:migrate:validate clientes')
        ->assertFailed()
        ->expectsOutputToContain('Nenhum cliente triado para validar');
});

it('recusa assinar enquanto houver cadastro pendente de triagem', function () {
    stgParaValidar(10);
    stgParaValidar(20, ['email' => 'b@exemplo.test'], ['status_triagem' => 'pending']);
    app(LoadWooCustomers::class)->executar();

    // Linha pendente não carrega e não aparece em contagem nenhuma —
    // sumiria calada, e a assinatura cobriria um número que ignora gente.
    $this->artisan('erp:migrate:validate clientes')
        ->assertFailed()
        ->expectsOutputToContain('continuam pendentes de triagem');
});

it('não confunde o comando de catálogo com o de clientes', function () {
    // O padrão continua sendo o catálogo: a doc e a memória da operação
    // registram `erp:migrate:validate` sem argumento, e ele não pode passar
    // a validar outra coisa.
    $this->artisan('erp:migrate:validate')
        ->assertFailed()
        ->expectsOutputToContain('Nada aprovado para validar');
});
