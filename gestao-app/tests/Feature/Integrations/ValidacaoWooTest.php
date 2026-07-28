<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Integrations\WooCommerce\Models\StgWooCategory;
use App\Modules\Integrations\WooCommerce\Models\StgWooProduct;
use App\Modules\Integrations\WooCommerce\Services\LoadWooCatalog;
use App\Modules\Integrations\WooCommerce\Services\ValidateWooCatalog;

/*
|--------------------------------------------------------------------------
| Validação da migração — docs/17-Migracao F5
|--------------------------------------------------------------------------
|
| A conferência que fecha o Gate 01. O que ela precisa provar: contagens
| batem (origem × destino) e a amostra reflete fielmente a origem — e que
| ela ACUSA quando não reflete, senão seria um verde decorativo.
|
*/

/** Uma linha aprovada, pronta para carregar e depois validar. */
function stgAprovado(int $wooId, string $sku, array $payload = []): StgWooProduct
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

it('confirma que o catálogo reflete a origem quando a carga foi fiel', function () {
    StgWooCategory::query()->create(['woo_id' => 5, 'name' => 'Budas', 'slug' => 'budas', 'payload' => []]);
    stgAprovado(100, 'DA-0001', [
        'name' => 'Buda sidarta 11 cm',
        'regular_price' => '129.90',
        'weight' => '0.450',
        'attributes' => [['slug' => 'pa_cor', 'options' => ['Dourado']]],
        'categories' => [['id' => 5, 'name' => 'Budas', 'slug' => 'budas']],
    ]);

    app(LoadWooCatalog::class)->executar();

    $r = app(ValidateWooCatalog::class)->executar();

    expect($r['contagens']['bate'])->toBeTrue()
        ->and($r['contagens']['aprovados'])->toBe(1)
        ->and($r['contagens']['mapeados'])->toBe(1)
        ->and($r['divergentes'])->toBe(0)
        ->and($r['amostra'][0]['divergencias'])->toBe([]);
});

it('acusa contagem que não bate', function () {
    stgAprovado(100, 'DA-0001');
    app(LoadWooCatalog::class)->executar();

    // Aprova um segundo no staging sem carregá-lo: 2 aprovados, 1 mapeado.
    stgAprovado(101, 'DA-0002');

    $r = app(ValidateWooCatalog::class)->executar();

    expect($r['contagens']['aprovados'])->toBe(2)
        ->and($r['contagens']['mapeados'])->toBe(1)
        ->and($r['contagens']['bate'])->toBeFalse();
});

it('acusa nome que não reflete a origem', function () {
    stgAprovado(100, 'DA-0001', ['name' => 'Nome da origem']);
    app(LoadWooCatalog::class)->executar();

    // Alguém mexeu no catálogo depois da carga — o nome deixou de refletir
    // o que veio do Woo. A F5 tem de pegar isso.
    Product::query()->where('sku', 'DA-0001')->update(['name' => 'Nome adulterado']);

    $r = app(ValidateWooCatalog::class)->executar();

    expect($r['divergentes'])->toBe(1)
        ->and($r['amostra'][0]['divergencias'][0])->toContain('nome');
});

it('acusa preço que não reflete a origem', function () {
    stgAprovado(100, 'DA-0001', ['regular_price' => '129.90']);
    app(LoadWooCatalog::class)->executar();

    $produto = Product::query()->where('sku', 'DA-0001')->first();
    $produto->prices()->update(['price' => '99.90']);

    $r = app(ValidateWooCatalog::class)->executar();

    expect($r['amostra'][0]['divergencias'])->toContain('preço de varejo diverge da origem');
});

it('não acusa preço escrito com outro numero de casas', function () {
    // A origem manda "89.9", o catálogo guarda "89.90" — o mesmo dinheiro.
    stgAprovado(100, 'DA-0001', ['regular_price' => '89.9']);
    app(LoadWooCatalog::class)->executar();

    $r = app(ValidateWooCatalog::class)->executar();

    expect($r['divergentes'])->toBe(0);
});

it('acusa quando a categoria escolhida é de vitrine', function () {
    // O erro que a carga já corrigiu — a validação guarda contra ele voltar.
    StgWooCategory::query()->create(['woo_id' => 382, 'name' => 'HOME SITE', 'slug' => 'home-site', 'payload' => []]);
    StgWooCategory::query()->create(['woo_id' => 5, 'name' => 'Budas', 'slug' => 'budas', 'payload' => []]);
    stgAprovado(100, 'DA-0001', ['categories' => [
        ['id' => 382, 'name' => 'HOME SITE', 'slug' => 'home-site'],
        ['id' => 5, 'name' => 'Budas', 'slug' => 'budas'],
    ]]);
    app(LoadWooCatalog::class)->executar();

    // Força a categoria errada no catálogo para simular o bug.
    $vitrine = ProductCategory::query()->where('name', 'HOME SITE')->value('id');
    Product::query()->where('sku', 'DA-0001')->update(['product_category_id' => $vitrine]);

    $r = app(ValidateWooCatalog::class)->executar();

    expect($r['amostra'][0]['divergencias'][0])->toContain('vitrine');
});

it('recusa validar banco vazio em vez de dar falso-verde', function () {
    // 0 aprovados = migração ausente, não fiel. "0 = 0" batendo faria o
    // comando mandar assinar a F5 sobre o nada — o engano provável é
    // rodar no banco local em vez de produção.
    $this->artisan('erp:migrate:validate')
        ->assertFailed()
        ->expectsOutputToContain('Nada aprovado para validar');
});

it('amostra de forma estável — a mesma rodada devolve os mesmos produtos', function () {
    for ($i = 1; $i <= 50; $i++) {
        stgAprovado(100 + $i, sprintf('DA-%04d', $i));
    }
    app(LoadWooCatalog::class)->executar();

    $skus = fn (array $r): array => array_column($r['amostra'], 'sku');

    $primeira = app(ValidateWooCatalog::class)->executar(10);
    $segunda = app(ValidateWooCatalog::class)->executar(10);

    expect($skus($primeira))->toBe($skus($segunda))
        ->and($primeira['conferidos'])->toBe(10);
});
