<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Integrations\WooCommerce\Client;
use App\Modules\Integrations\WooCommerce\Exceptions\IntegracaoWooIndisponivel;
use App\Modules\Integrations\WooCommerce\Models\ImportBatch;
use App\Modules\Integrations\WooCommerce\Models\StgWooCategory;
use App\Modules\Integrations\WooCommerce\Models\StgWooProduct;
use App\Modules\Integrations\WooCommerce\Services\ExtractWooCatalog;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Extração do WooCommerce — docs/17-Migracao F2, ADR-0010
|--------------------------------------------------------------------------
|
| A API é falsificada em todos os testes: a suíte não pode depender de um
| site externo estar de pé, e o `preventStrayRequests` do TestCase já
| reprovaria qualquer chamada que escapasse.
|
| O que se prova aqui é o que a fase 2 promete: trazer o bruto como ele é,
| paginar até o fim, não duplicar ao repetir, e não perder as variações —
| que não vêm no mesmo endpoint dos produtos.
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

/** Um produto do Woo, no formato que a API devolve. */
function produtoWoo(int $id, array $extra = []): array
{
    return array_merge([
        'id' => $id,
        'name' => "Produto {$id}",
        'type' => 'simple',
        'status' => 'publish',
        // Vazio em 716 de 716 no catálogo real (pasta 31 §3.1).
        'sku' => '',
        'regular_price' => '89.90',
        'weight' => '0.45',
        'date_modified_gmt' => '2026-07-01T10:00:00',
    ], $extra);
}

it('traz todas as páginas até a última', function () {
    Http::fake([
        '*/products?*page=1*' => Http::response([produtoWoo(1), produtoWoo(2)]),
        // Página incompleta encerra a paginação — sem pedir uma terceira.
        '*/products?*page=2*' => Http::response([produtoWoo(3)]),
        '*' => Http::response([]),
    ]);

    $lote = app(ExtractWooCatalog::class)->produtos();

    expect(StgWooProduct::query()->count())->toBe(3)
        ->and($lote->fetched)->toBe(3)
        ->and($lote->stored)->toBe(3)
        ->and($lote->status)->toBe('completed');
});

it('não duplica ao rodar duas vezes', function () {
    // Um fake só, com a resposta mudando por referência. Chamar
    // `Http::fake()` de novo **acumula** stubs em vez de substituir, então
    // o primeiro continuaria vencendo e o teste passaria sem provar nada.
    $nome = 'Nome antigo';

    Http::fake(function ($request) use (&$nome) {
        return str_contains($request->url(), '/products?') && str_contains($request->url(), 'page=1')
            ? Http::response([produtoWoo(1, ['name' => $nome])])
            : Http::response([]);
    });

    $extrator = app(ExtractWooCatalog::class);
    $extrator->produtos();

    $nome = 'Nome novo';
    $extrator->produtos();

    // BR-706: re-executar atualiza, não duplica. É o que torna a extração
    // retomável depois de uma queda no meio.
    expect(StgWooProduct::query()->count())->toBe(1)
        ->and(StgWooProduct::query()->first()->name)->toBe('Nome novo');
});

it('busca as variações, que não vêm no endpoint de produtos', function () {
    Http::fake([
        // Página a página, e não um curinga para o endpoint inteiro: com
        // `per_page=2`, duas variações preenchem a página e a paginação
        // pede a próxima. Um fake que responde igual a qualquer página
        // faria o laço girar para sempre.
        '*/products/10/variations?*page=1*' => Http::response([
            ['id' => 101, 'sku' => '', 'regular_price' => '99.00'],
            ['id' => 102, 'sku' => '', 'regular_price' => '99.00'],
        ]),
        '*/products?*page=1*' => Http::response([produtoWoo(10, ['type' => 'variable'])]),
        '*' => Http::response([]),
    ]);

    app(ExtractWooCatalog::class)->produtos();

    // Pelo ADR-0022 cada variação vira produto próprio no ERP. Deixá-las
    // de fora perderia 77 itens que a operação vende.
    expect(StgWooProduct::query()->where('type', 'variation')->count())->toBe(2)
        ->and(StgWooProduct::query()->where('woo_id', 101)->first()->parent_woo_id)->toBe(10);
});

it('grava SKU vazio como nulo, para a triagem poder contar', function () {
    Http::fake([
        '*/products?*page=1*' => Http::response([produtoWoo(1, ['sku' => '']), produtoWoo(2, ['sku' => 'TEM-SKU'])]),
        '*' => Http::response([]),
    ]);

    app(ExtractWooCatalog::class)->produtos();

    expect(StgWooProduct::query()->whereNull('sku')->count())->toBe(1)
        ->and(StgWooProduct::query()->where('woo_id', 2)->first()->sku)->toBe('TEM-SKU');
});

it('guarda o payload inteiro para reprocessar sem bater na API de novo', function () {
    Http::fake([
        '*/products?*page=1*' => Http::response([produtoWoo(1, ['images' => [['src' => 'https://x/y.jpg']]])]),
        '*' => Http::response([]),
    ]);

    app(ExtractWooCatalog::class)->produtos();

    $bruto = StgWooProduct::query()->first()->payload;

    expect($bruto['images'][0]['src'])->toBe('https://x/y.jpg');
});

it('não grava nada em dry-run, mas conta o que viria', function () {
    Http::fake([
        '*/products?*page=1*' => Http::response([produtoWoo(1), produtoWoo(2)]),
        '*' => Http::response([]),
    ]);

    $lote = app(ExtractWooCatalog::class)->produtos(dryRun: true);

    expect(StgWooProduct::query()->count())->toBe(0)
        ->and($lote->fetched)->toBe(2)
        ->and($lote->stored)->toBe(0)
        ->and($lote->dry_run)->toBeTrue();
});

it('registra a última página concluída quando falha no meio', function () {
    Http::fake([
        '*/products?*page=1*' => Http::response([produtoWoo(1), produtoWoo(2)]),
        '*/products?*page=2*' => Http::response('erro do servidor', 500),
        '*' => Http::response([]),
    ]);

    expect(fn () => app(ExtractWooCatalog::class)->produtos())
        ->toThrow(IntegracaoWooIndisponivel::class);

    $lote = ImportBatch::query()->latest('id')->first();

    // Sem `last_page`, retomar significaria recomeçar da primeira página.
    expect($lote->status)->toBe('failed')
        ->and($lote->last_page)->toBe(1)
        ->and($lote->error)->toContain('500');

    // E o que já entrou continua lá — a falha não desfaz o progresso.
    expect(StgWooProduct::query()->count())->toBe(2);
});

it('extrai categorias tratando raiz como nulo', function () {
    Http::fake([
        '*/products/categories?*page=1*' => Http::response([
            ['id' => 5, 'name' => 'Budas', 'slug' => 'budas', 'parent' => 0, 'count' => 30],
            ['id' => 6, 'name' => 'Infantis', 'slug' => 'infantis', 'parent' => 5, 'count' => 12],
        ]),
        '*' => Http::response([]),
    ]);

    app(ExtractWooCatalog::class)->categorias();

    expect(StgWooCategory::query()->where('woo_id', 5)->first()->parent_woo_id)->toBeNull()
        ->and(StgWooCategory::query()->where('woo_id', 6)->first()->parent_woo_id)->toBe(5);
});

it('desiste quando o endpoint ignora a paginação', function () {
    config()->set('integrations.woocommerce.max_pages', 5);

    // Curinga de propósito: responde sempre a mesma página cheia, que é o
    // que um plugin do WordPress mal-comportado faz. Sem a trava, isto
    // rodaria até estourar a memória — e foi assim que o problema
    // apareceu, num teste que travou a suíte inteira.
    Http::fake(['*' => Http::response([produtoWoo(1), produtoWoo(2)])]);

    expect(fn () => app(ExtractWooCatalog::class)->produtos())
        ->toThrow(IntegracaoWooIndisponivel::class, 'ignorando o parâmetro');
});

it('recusa rodar com a integração desligada', function () {
    config()->set('integrations.woocommerce.enabled', false);

    // Uma integração que nasce ligada dispara contra produção no primeiro
    // deploy de quem só queria rodar os testes.
    expect(fn () => app(Client::class)->get('products'))
        ->toThrow(IntegracaoWooIndisponivel::class, 'desligada');
});

it('avisa qual credencial falta, em vez de estourar no meio', function () {
    config()->set('integrations.woocommerce.key', null);

    expect(fn () => app(Client::class)->get('products'))
        ->toThrow(IntegracaoWooIndisponivel::class, 'WOO_KEY');
});

it('não carrega nada no catálogo — extração para no staging', function () {
    Http::fake([
        '*/products?*page=1*' => Http::response([produtoWoo(1)]),
        '*' => Http::response([]),
    ]);

    app(ExtractWooCatalog::class)->produtos();

    // A fronteira entre F2 e F4 é o que permite rodar a extração à
    // vontade sem risco: o catálogo do ERP não é tocado.
    expect(Product::query()->count())->toBe(0);
});
