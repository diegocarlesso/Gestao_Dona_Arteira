<?php

declare(strict_types=1);

use App\Modules\Integrations\WooCommerce\Enums\DestinoDoCliente;
use App\Modules\Integrations\WooCommerce\Models\StgWooCustomer;
use App\Modules\Integrations\WooCommerce\Services\TriageWooCustomers;
use App\Modules\Sales\Models\Customer;

/*
|--------------------------------------------------------------------------
| Saneamento dos clientes — docs/17-Migracao F3
|--------------------------------------------------------------------------
|
| A regra que estes testes protegem: **a rejeição é o produto principal**.
| Dos 198 cadastros do site, 136 não migram (decisão de 2026-08-10,
| minimização de dado pessoal), e cada um deles sai daqui com o motivo
| escrito — nada é descartado em silêncio (princípio 4 da pasta 17).
|
*/

/** Uma linha de staging, no formato que a extração grava. */
function stgBruto(int $wooId, array $payload = [], ?int $pedidos = 1): StgWooCustomer
{
    $payload = array_merge([
        'id' => $wooId,
        'email' => "cliente{$wooId}@exemplo.test",
        'first_name' => 'Maria',
        'last_name' => 'Silva',
    ], $payload);

    return StgWooCustomer::query()->create([
        'woo_id' => $wooId,
        'email' => isset($payload['email']) ? mb_strtolower((string) $payload['email']) : null,
        'first_name' => $payload['first_name'] ?: null,
        'last_name' => $payload['last_name'] ?: null,
        'orders_count' => $pedidos,
        'payload' => $payload,
        'status_triagem' => 'pending',
    ]);
}

it('classifica quem comprou como cliente', function () {
    stgBruto(1, pedidos: 3);

    app(TriageWooCustomers::class)->executar();

    expect(StgWooCustomer::first()->status_triagem)->toBe(DestinoDoCliente::Cliente->value)
        ->and(StgWooCustomer::first()->motivo_triagem)->toContain('comprou 3×');
});

it('rejeita quem nunca comprou, com o motivo escrito', function () {
    stgBruto(1, pedidos: 0);

    app(TriageWooCustomers::class)->executar();

    $linha = StgWooCustomer::first();

    // São ~136 dos 198 cadastros: checkout abandonado, newsletter,
    // importação antiga. Guardá-los seria dado pessoal sem finalidade de
    // venda nem obrigação fiscal (pasta 25 §3).
    expect($linha->status_triagem)->toBe(DestinoDoCliente::SemPedido->value)
        ->and($linha->motivo_triagem)->toContain('minimização de dados');
});

it('deixa pendente quem veio sem `orders_count`, em vez de rejeitar', function () {
    stgBruto(1, pedidos: null);

    app(TriageWooCustomers::class)->executar();

    // Rejeitar por dado ausente descartaria um comprador em silêncio, que é
    // exatamente o que a fase promete não fazer.
    expect(StgWooCustomer::first()->status_triagem)->toBe(DestinoDoCliente::Pendente->value)
        ->and(StgWooCustomer::first()->motivo_triagem)->toContain('não dá para decidir');
});

it('propõe o nome juntando primeiro e último', function () {
    stgBruto(1, ['first_name' => 'Ana', 'last_name' => 'Prado']);

    app(TriageWooCustomers::class)->executar();

    expect(StgWooCustomer::first()->nome_proposto)->toBe('Ana Prado');
});

it('usa o nome da cobrança quando o perfil está vazio', function () {
    stgBruto(1, [
        'first_name' => '',
        'last_name' => '',
        'billing' => ['first_name' => 'João', 'last_name' => 'Souza'],
    ]);

    app(TriageWooCustomers::class)->executar();

    // O checkout preenche um campo que o perfil não tem. Não é inventar
    // dado: é ler o outro lugar onde a origem o guarda.
    expect(StgWooCustomer::first()->nome_proposto)->toBe('João Souza');
});

it('cai para o mesmo recuo que o pedido do site já usa', function () {
    stgBruto(1, ['first_name' => '', 'last_name' => '']);

    app(TriageWooCustomers::class)->executar();

    // Dois rótulos diferentes para a mesma lacuna fariam a operação achar
    // que são duas situações diferentes.
    expect(StgWooCustomer::first()->nome_proposto)->toBe('Cliente do site');
});

it('garimpa o CPF do metadado do plugin brasileiro', function () {
    stgBruto(1, ['meta_data' => [['key' => '_billing_cpf', 'value' => '529.982.247-25']]]);

    app(TriageWooCustomers::class)->executar();

    // Só dígitos: a máscara é apresentação, e guardá-la faria o mesmo CPF
    // escrito de dois jeitos passar pela unicidade do banco.
    expect(StgWooCustomer::first()->doc_proposto)->toBe('52998224725');
});

it('lê o CPF do bloco de cobrança quando o plugin o expõe no REST', function () {
    stgBruto(1, ['billing' => ['cpf' => '52998224725']]);

    app(TriageWooCustomers::class)->executar();

    expect(StgWooCustomer::first()->doc_proposto)->toBe('52998224725');
});

it('mantém o CPF que não fecha no dígito, como pendência', function () {
    stgBruto(1, ['meta_data' => [['key' => 'billing_cpf', 'value' => '111.111.111-11']]]);

    app(TriageWooCustomers::class)->executar();

    // Decisão da pasta 17 F3: documento inválido não bloqueia a migração —
    // o cliente entra e a pendência fiscal aparece no cadastro (BR-001).
    expect(StgWooCustomer::first()->doc_proposto)->toBe('11111111111');
});

it('registra no motivo o documento que descartou', function () {
    stgBruto(1, ['meta_data' => [['key' => '_billing_cpf', 'value' => '1234567890123456789']]]);

    app(TriageWooCustomers::class)->executar();

    $linha = StgWooCustomer::first();

    // Mais de 14 dígitos não é CPF nem CNPJ, e truncar produziria um
    // documento que não é de ninguém. Some do cadastro, não do relatório.
    expect($linha->doc_proposto)->toBeNull()
        ->and($linha->motivo_triagem)->toContain('documento da origem descartado');
});

it('não propõe nada para quem não migra', function () {
    stgBruto(1, ['meta_data' => [['key' => '_billing_cpf', 'value' => '52998224725']]], pedidos: 0);

    app(TriageWooCustomers::class)->executar();

    // Garimpar CPF de quem não vai migrar seria deixar dado pessoal no
    // staging sem finalidade nenhuma.
    expect(StgWooCustomer::first()->doc_proposto)->toBeNull()
        ->and(StgWooCustomer::first()->nome_proposto)->toBeNull();
});

it('marca como duplicado o cadastro mais antigo com o mesmo e-mail', function () {
    stgBruto(10, ['email' => 'ana@exemplo.test']);
    stgBruto(20, ['email' => 'ana@exemplo.test']);

    app(TriageWooCustomers::class)->executar();

    // Não deveria acontecer (o inventário mediu zero duplicatas entre os 62
    // compradores), mas descobrir isso como violação de UNIQUE no meio da
    // carga seria descobrir tarde demais.
    //
    // Sem data de modificação na origem, o `woo_id` maior é o cadastro mais
    // novo — e "mais recente vence" é a última regra do desempate da
    // pasta 17 F3: fica o que a pessoa usou por último, o outro é o
    // abandonado.
    expect(StgWooCustomer::where('woo_id', 20)->first()->status_triagem)->toBe(DestinoDoCliente::Cliente->value)
        ->and(StgWooCustomer::where('woo_id', 10)->first()->status_triagem)->toBe(DestinoDoCliente::Duplicado->value)
        ->and(StgWooCustomer::where('woo_id', 10)->first()->motivo_triagem)->toContain('Woo #20');
});

it('no empate de duplicados, o documento válido vence', function () {
    stgBruto(10, [
        'email' => 'ana@exemplo.test',
        'meta_data' => [['key' => '_billing_cpf', 'value' => '111.111.111-11']],
    ]);
    stgBruto(20, [
        'email' => 'ana@exemplo.test',
        'meta_data' => [['key' => '_billing_cpf', 'value' => '529.982.247-25']],
    ]);

    app(TriageWooCustomers::class)->executar();

    // Regra da pasta 17 F3: doc válido > e-mail > mais recente. O CPF
    // identifica a pessoa; o e-mail identifica uma caixa postal.
    expect(StgWooCustomer::where('woo_id', 20)->first()->status_triagem)->toBe(DestinoDoCliente::Cliente->value)
        ->and(StgWooCustomer::where('woo_id', 10)->first()->status_triagem)->toBe(DestinoDoCliente::Duplicado->value);
});

it('decide o mesmo ao rodar a triagem de novo', function () {
    stgBruto(10);
    stgBruto(20, pedidos: 0);

    $triagem = app(TriageWooCustomers::class);
    $triagem->executar();
    $primeira = StgWooCustomer::orderBy('woo_id')->pluck('status_triagem', 'woo_id')->all();

    $triagem->executar();

    // Conferência humana feita sobre uma rodada não pode virar lixo na
    // seguinte — a mesma exigência dos SKUs propostos no catálogo.
    expect(StgWooCustomer::orderBy('woo_id')->pluck('status_triagem', 'woo_id')->all())->toBe($primeira);
});

it('não cadastra ninguém', function () {
    stgBruto(1);

    app(TriageWooCustomers::class)->executar();

    expect(Customer::query()->count())->toBe(0);
});

it('lista os rejeitados com o e-mail mascarado', function () {
    stgBruto(1, ['email' => 'maria.silva@gmail.com'], pedidos: 0);

    app(TriageWooCustomers::class)->executar();

    // O motivo de não migrá-los é não espalhar o dado deles; imprimir 136
    // e-mails no terminal contrariaria a própria decisão.
    $this->artisan('erp:migrate:triage clientes --rejeitados')
        ->expectsOutputToContain('m***a@gmail.com')
        ->assertSuccessful();
});
