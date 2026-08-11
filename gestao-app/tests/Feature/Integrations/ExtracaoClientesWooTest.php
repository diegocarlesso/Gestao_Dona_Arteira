<?php

declare(strict_types=1);

use App\Modules\Integrations\WooCommerce\Models\StgWooCustomer;
use App\Modules\Integrations\WooCommerce\Services\ExtractWooCustomers;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Extração de clientes do WooCommerce — docs/17-Migracao F2
|--------------------------------------------------------------------------
|
| A API é falsificada em todos os testes: a suíte não pode depender de um
| site externo estar de pé, e nunca deve tocar dado pessoal de verdade — o
| `preventStrayRequests` do TestCase reprova qualquer chamada que escape.
|
| O que se prova aqui: a extração traz o bruto como ele é (inclusive quem
| nunca comprou, que a triagem vai rejeitar), não duplica ao repetir, e não
| encosta no cadastro de clientes do ERP.
|
*/

beforeEach(function () {
    config()->set('integrations.woocommerce', [
        'enabled' => true,
        'url' => 'https://exemplo.test',
        'key' => 'ck_teste',
        'secret' => 'cs_teste',
        'per_page' => 2,
        'timeout' => 5,
    ]);
});

/** Um cliente do Woo, no formato que a API devolve. */
function clienteWoo(int $id, array $extra = []): array
{
    return array_merge([
        'id' => $id,
        'email' => "cliente{$id}@exemplo.test",
        'first_name' => 'Maria',
        'last_name' => 'Silva',
        'orders_count' => 1,
        'date_modified_gmt' => '2026-07-01T10:00:00',
        'billing' => [
            'first_name' => 'Maria',
            'last_name' => 'Silva',
            'address_1' => 'Rua das Flores',
            'city' => 'Jacutinga',
            'state' => 'RS',
            'postcode' => '99570-000',
            'phone' => '(54) 99999-1234',
        ],
        'meta_data' => [
            ['key' => '_billing_cpf', 'value' => '529.982.247-25'],
        ],
    ], $extra);
}

it('traz todas as páginas até a última', function () {
    Http::fake([
        '*/customers?*page=1*' => Http::response([clienteWoo(1), clienteWoo(2)]),
        // Página incompleta encerra a paginação — sem pedir uma terceira.
        '*/customers?*page=2*' => Http::response([clienteWoo(3)]),
        '*' => Http::response([]),
    ]);

    $lote = app(ExtractWooCustomers::class)->clientes();

    expect(StgWooCustomer::query()->count())->toBe(3)
        ->and($lote->fetched)->toBe(3)
        ->and($lote->stored)->toBe(3)
        ->and($lote->entity)->toBe('customers')
        ->and($lote->status)->toBe('completed');
});

it('traz quem nunca comprou, para a triagem poder rejeitar em voz alta', function () {
    Http::fake([
        '*/customers?*page=1*' => Http::response([
            clienteWoo(1, ['orders_count' => 3]),
            clienteWoo(2, ['orders_count' => 0]),
        ]),
        '*' => Http::response([]),
    ]);

    app(ExtractWooCustomers::class)->clientes();

    // Filtrar aqui economizaria linhas e custaria a prestação de contas:
    // ninguém conseguiria dizer depois quantos ficaram de fora e por quê.
    expect(StgWooCustomer::query()->count())->toBe(2)
        ->and(StgWooCustomer::query()->where('woo_id', 2)->first()->orders_count)->toBe(0);
});

it('guarda `orders_count` ausente como nulo, e não como zero', function () {
    Http::fake([
        '*/customers?*page=1*' => Http::response([clienteWoo(1, ['orders_count' => null])]),
        '*' => Http::response([]),
    ]);

    app(ExtractWooCustomers::class)->clientes();

    // "Não deu para saber" é diferente de "nunca comprou": o zero rejeita,
    // o nulo fica pendente de decisão humana. Confundir os dois descartaria
    // um comprador em silêncio.
    expect(StgWooCustomer::query()->first()->orders_count)->toBeNull();
});

it('normaliza o e-mail em minúsculas já na entrada', function () {
    Http::fake([
        '*/customers?*page=1*' => Http::response([clienteWoo(1, ['email' => '  Maria@Exemplo.TEST '])]),
        '*' => Http::response([]),
    ]);

    app(ExtractWooCustomers::class)->clientes();

    // O e-mail é a chave de dedupe nº 1 (de-para da pasta 16). Guardado
    // como veio, a busca por duplicata dependeria de o MariaDB e o SQLite
    // compararem texto do mesmo jeito — e eles não comparam.
    expect(StgWooCustomer::query()->first()->email)->toBe('maria@exemplo.test');
});

it('não duplica ao rodar duas vezes', function () {
    // Um fake só, com a resposta mudando por referência. Chamar
    // `Http::fake()` de novo **acumula** stubs em vez de substituir.
    $nome = 'Maria';

    Http::fake(function ($request) use (&$nome) {
        return str_contains($request->url(), '/customers?') && str_contains($request->url(), 'page=1')
            ? Http::response([clienteWoo(1, ['first_name' => $nome])])
            : Http::response([]);
    });

    $extrator = app(ExtractWooCustomers::class);
    $extrator->clientes();

    $nome = 'Maria Aparecida';
    $extrator->clientes();

    // BR-706: re-executar atualiza, não duplica. É o que torna a extração
    // retomável depois de uma queda no meio.
    expect(StgWooCustomer::query()->count())->toBe(1)
        ->and(StgWooCustomer::query()->first()->first_name)->toBe('Maria Aparecida');
});

it('guarda o payload inteiro, inclusive endereços e metadados', function () {
    Http::fake([
        '*/customers?*page=1*' => Http::response([clienteWoo(1)]),
        '*' => Http::response([]),
    ]);

    app(ExtractWooCustomers::class)->clientes();

    $bruto = StgWooCustomer::query()->first()->payload;

    // O endereço e o CPF só existem aqui: nenhum vira coluna. Sem o payload
    // completo, reprocessar a carga exigiria bater na API de novo — o que,
    // com dado pessoal, é uma leitura a mais que não precisava acontecer.
    expect($bruto['billing']['address_1'])->toBe('Rua das Flores')
        ->and($bruto['meta_data'][0]['key'])->toBe('_billing_cpf');
});

it('não grava nada em dry-run, mas conta o que viria', function () {
    Http::fake([
        '*/customers?*page=1*' => Http::response([clienteWoo(1), clienteWoo(2)]),
        '*' => Http::response([]),
    ]);

    $lote = app(ExtractWooCustomers::class)->clientes(dryRun: true);

    expect(StgWooCustomer::query()->count())->toBe(0)
        ->and($lote->fetched)->toBe(2)
        ->and($lote->stored)->toBe(0)
        ->and($lote->dry_run)->toBeTrue();
});

it('não cadastra ninguém — extração para no staging', function () {
    Http::fake([
        '*/customers?*page=1*' => Http::response([clienteWoo(1)]),
        '*' => Http::response([]),
    ]);

    app(ExtractWooCustomers::class)->clientes();

    // A fronteira entre F2 e F4 é o que permite rodar a extração à vontade
    // sem risco: o cadastro de clientes do ERP não é tocado.
    expect(Customer::query()->count())->toBe(0);
});

it('extrai pelo comando e compara com o inventário no relatório', function () {
    Http::fake([
        '*/customers?*page=1*' => Http::response([
            clienteWoo(1, ['orders_count' => 2]),
            clienteWoo(2, ['orders_count' => 0]),
        ]),
        '*' => Http::response([]),
    ]);

    // Os números da pasta 31 §2 saem ao lado dos medidos: é aqui que se vê
    // se a extração conta o mesmo que o inventário, e perguntar agora é
    // muito mais barato do que depois da carga.
    $this->artisan('erp:migrate:extract clientes')
        ->expectsOutputToContain('com pedido')
        ->assertSuccessful();

    expect(StgWooCustomer::query()->count())->toBe(2);
});

it('não busca clientes quando o comando pede `tudo`', function () {
    Http::fake([
        '*/products/categories?*' => Http::response([]),
        '*/products?*' => Http::response([]),
        '*' => Http::response([]),
    ]);

    $this->artisan('erp:migrate:extract tudo')->assertSuccessful();

    // Extrair pessoas é copiar dado pessoal para o ERP (LGPD, pasta 25 §3):
    // tem de ser ato deliberado, e não efeito colateral de quem só queria
    // reextrair o catálogo.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/customers'));
});
