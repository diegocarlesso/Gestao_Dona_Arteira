<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Integrations\WooCommerce\Models\StgWooCategory;
use App\Modules\Integrations\WooCommerce\Models\StgWooProduct;
use App\Modules\Integrations\WooCommerce\Services\LoadWooCatalog;

/*
|--------------------------------------------------------------------------
| Carga do staging para o catálogo — docs/17-Migracao F4
|--------------------------------------------------------------------------
|
| Só entra o aprovado, o SKU vem da proposta conferida, e rodar duas vezes
| não duplica (BR-706) — sem isso, corrigir algo no staging exigiria
| limpar o catálogo.
|
*/

/** Uma linha aprovada, pronta para carregar. */
function aprovado(int $wooId, string $sku, array $payload = []): StgWooProduct
{
    return StgWooProduct::query()->create([
        'woo_id' => $wooId,
        'type' => 'simple',
        'name' => $payload['name'] ?? "Peça {$wooId}",
        'nome_proposto' => $payload['name'] ?? "Peça {$wooId}",
        'sku_proposto' => $sku,
        'status' => 'publish',
        'regular_price' => $payload['regular_price'] ?? '89.90',
        'weight' => $payload['weight'] ?? '0.450',
        'payload' => array_merge(['id' => $wooId], $payload),
        'status_triagem' => 'produto',
        'aprovado_em' => now(),
    ]);
}

it('cria o produto com o SKU aprovado, não com um gerado', function () {
    aprovado(100, 'DA-0042');

    app(LoadWooCatalog::class)->executar();

    // Na migração o código foi decidido antes e conferido por gente; o
    // gerador daria DA-0001 e desfaria a conferência.
    expect(Product::query()->value('sku'))->toBe('DA-0042');
});

it('ignora o que não foi aprovado', function () {
    aprovado(100, 'DA-0001')->forceFill(['aprovado_em' => null])->save();

    app(LoadWooCatalog::class)->executar();

    expect(Product::query()->count())->toBe(0);
});

it('não duplica ao carregar duas vezes', function () {
    aprovado(100, 'DA-0001', ['name' => 'Nome antigo']);

    $carga = app(LoadWooCatalog::class);
    $carga->executar();

    StgWooProduct::query()->where('woo_id', 100)->update(['nome_proposto' => 'Nome corrigido']);
    $carga->executar();

    // BR-706 pelo `integration_mappings`: recarregar atualiza. É o que
    // permite corrigir o staging sem limpar o catálogo.
    expect(Product::query()->count())->toBe(1)
        ->and(Product::query()->value('name'))->toBe('Nome corrigido');
});

it('converte o peso de quilos para gramas', function () {
    aprovado(100, 'DA-0001', ['weight' => '0.450']);

    app(LoadWooCatalog::class)->executar();

    // O Woo guarda em kg, o ERP em g. Sem conversão o produto pesaria
    // meio grama e o frete sairia errado.
    expect((float) Product::query()->value('weight_g'))->toBe(450.0);
});

it('recusa peso implausível em vez de carregar valor absurdo', function () {
    // Três produtos reais trazem "970" num campo em quilos — gramas
    // digitadas no lugar errado.
    aprovado(100, 'DA-0001', ['weight' => '970']);

    app(LoadWooCatalog::class)->executar();

    // Peso errado quebraria o frete em silêncio; sem peso, o produto
    // aparece no relatório de cadastro incompleto.
    expect(Product::query()->value('weight_g'))->toBeNull()
        ->and(Product::query()->first()->pendencias())->toContain('sem peso');
});

it('registra o preço de varejo e deixa o atacado vazio', function () {
    aprovado(100, 'DA-0001', ['regular_price' => '129.90']);

    app(LoadWooCatalog::class)->executar();

    $produto = Product::query()->first();

    // O Woo é canal de varejo — o atacado nunca esteve em sistema nenhum.
    expect($produto->precoVigente(PriceList::Retail)->price)->toBe('129.90')
        ->and($produto->temPrecoDe(PriceList::Wholesale))->toBeFalse();
});

it('monta a árvore de categorias mesmo com a filha vindo antes da mãe', function () {
    // A API não garante ordem por profundidade; uma passada só quebraria
    // na FK quando a subcategoria chegasse primeiro.
    StgWooCategory::query()->create(['woo_id' => 6, 'name' => 'Infantis', 'slug' => 'infantis', 'parent_woo_id' => 5, 'payload' => []]);
    StgWooCategory::query()->create(['woo_id' => 5, 'name' => 'Budas', 'slug' => 'budas', 'payload' => []]);

    app(LoadWooCatalog::class)->executar();

    $filha = ProductCategory::query()->where('name', 'Infantis')->first();
    $mae = ProductCategory::query()->where('name', 'Budas')->first();

    expect($filha->parent_id)->toBe($mae->id)
        ->and($filha->caminho())->toBe('Budas › Infantis');
});

it('liga o produto à categoria pelo mapeamento', function () {
    StgWooCategory::query()->create(['woo_id' => 5, 'name' => 'Budas', 'slug' => 'budas', 'payload' => []]);
    aprovado(100, 'DA-0001', ['categories' => [['id' => 5, 'name' => 'Budas']]]);

    app(LoadWooCatalog::class)->executar();

    expect(Product::query()->first()->category->name)->toBe('Budas');
});

it('guarda o mapeamento com o id do Woo', function () {
    aprovado(100, 'DA-0001');

    app(LoadWooCatalog::class)->executar();

    $localId = Product::query()->value('id');

    // BR-704: sem o mapeamento, a sincronização pós-cutover criaria um
    // segundo produto a cada webhook em vez de atualizar o primeiro.
    //
    // O `not->toBeNull` não é redundante: sem ele o teste passaria com
    // os dois lados nulos, ou seja, passaria justamente quando a carga
    // não criasse produto algum — foi o que aconteceu na primeira rodada.
    expect($localId)->not->toBeNull()
        ->and(IntegrationMapping::localDe('product', 100))->toBe($localId);
});

it('arquiva o que não está publicado no site', function () {
    aprovado(100, 'DA-0001')->forceFill(['status' => 'draft'])->save();

    app(LoadWooCatalog::class)->executar();

    expect(Product::query()->first()->status)->toBe(ProductStatus::Archived);
});

it('traz a cor do anúncio como texto', function () {
    aprovado(100, 'DA-0001', [
        'attributes' => [['slug' => 'pa_cor', 'options' => ['Azul', 'Dourado']]],
    ]);

    app(LoadWooCatalog::class)->executar();

    // Decisão de 2026-07-27: um anúncio vira um produto, cor descritiva.
    expect(Product::query()->value('color'))->toBe('Azul, Dourado');
});

it('lê a cor da variação, que vem no singular', function () {
    aprovado(100, 'DA-0001', [
        'attributes' => [['slug' => 'pa_cor', 'option' => 'Rosê gold']],
    ]);

    app(LoadWooCatalog::class)->executar();

    // Produto usa `options`, variação usa `option` — ler só um dos dois
    // perderia metade dos casos.
    expect(Product::query()->value('color'))->toBe('Rosê gold');
});

it('guarda a referência da imagem, não o arquivo', function () {
    aprovado(100, 'DA-0001', [
        'images' => [[
            'id' => 555,
            'src' => 'https://donaarteira.com.br/wp-content/uploads/2025/07/7.jpg',
            'thumbnail' => 'https://donaarteira.com.br/wp-content/uploads/2025/07/7-150x150.jpg',
            'alt' => 'Buda azul',
        ]],
    ]);

    app(LoadWooCatalog::class)->executar();

    $imagem = Product::query()->first()->imagemPrincipal();

    // ADR-0017 fase 1: a mídia segue hospedada no WordPress; o ERP guarda
    // a URL e exibe. Migrar os arquivos agora custaria disco e pipeline.
    expect($imagem->url)->toContain('wp-content/uploads')
        ->and($imagem->source)->toBe('woo')
        ->and($imagem->alt)->toBe('Buda azul')
        // A prévia usa a miniatura: a foto inteira num quadro de 160 px
        // seria download desperdiçado.
        ->and($imagem->paraPrevia())->toContain('150x150');
});

it('lê a imagem da variação, que vem no singular', function () {
    aprovado(100, 'DA-0001', [
        'image' => ['id' => 9, 'src' => 'https://exemplo.test/v.jpg'],
    ]);

    app(LoadWooCatalog::class)->executar();

    // Mesmo padrão da cor: produto usa `images`, variação usa `image`.
    expect(Product::query()->first()->imagemPrincipal()->url)->toBe('https://exemplo.test/v.jpg');
});

it('cai para a imagem original quando não há miniatura', function () {
    aprovado(100, 'DA-0001', [
        'images' => [['id' => 1, 'src' => 'https://exemplo.test/inteira.jpg']],
    ]);

    app(LoadWooCatalog::class)->executar();

    expect(Product::query()->first()->imagemPrincipal()->paraPrevia())
        ->toBe('https://exemplo.test/inteira.jpg');
});

it('não duplica imagens ao recarregar', function () {
    aprovado(100, 'DA-0001', [
        'images' => [['id' => 7, 'src' => 'https://exemplo.test/a.jpg']],
    ]);

    $carga = app(LoadWooCatalog::class);
    $carga->executar();
    $carga->executar();

    expect(Product::query()->first()->images()->count())->toBe(1);
});
