<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Integrations\WooCommerce\Enums\DestinoDaTriagem;
use App\Modules\Integrations\WooCommerce\Models\StgWooProduct;
use App\Modules\Integrations\WooCommerce\Services\TriageWooProducts;

/*
|--------------------------------------------------------------------------
| Saneamento do catálogo — docs/17-Migracao F3
|--------------------------------------------------------------------------
|
| A regra que estes testes protegem: **a classificação não é uniforme por
| tipo do Woo**. Um `variable` vira produto ou é descartado dependendo de
| as variações dele existirem de verdade — descoberta dos dados reais, que
| contrariou o que a documentação supunha.
|
*/

/** Uma linha de staging, no formato que a extração grava. */
function stg(int $wooId, string $type, array $payload = [], ?int $pai = null): StgWooProduct
{
    return StgWooProduct::query()->create([
        'woo_id' => $wooId,
        'type' => $type,
        'parent_woo_id' => $pai,
        'name' => $payload['name'] ?? "Item {$wooId}",
        'payload' => array_merge(['id' => $wooId, 'type' => $type], $payload),
        'status_triagem' => 'pending',
    ]);
}

/** O atributo de composição de kit — o único eixo de variação real do catálogo. */
function atributoDeKit(string $peca): array
{
    return ['attributes' => [['id' => 29, 'slug' => 'pa_peca-kit-1', 'name' => 'QUAIS PEÇAS?', 'option' => $peca]]];
}

it('classifica produto simples como produto', function () {
    stg(1, 'simple');

    app(TriageWooProducts::class)->executar();

    expect(StgWooProduct::first()->status_triagem)->toBe(DestinoDaTriagem::Produto->value);
});

it('descarta o invólucro cujas variações são reais', function () {
    stg(10, 'variable');
    stg(101, 'variation', atributoDeKit('BUDA SIDARTA'), pai: 10);
    stg(102, 'variation', atributoDeKit('KIT COMPLETO'), pai: 10);

    app(TriageWooProducts::class)->executar();

    // No Woo o pai agrupa; o que se vende são as variações, e é cada uma
    // delas que vira produto (ADR-0022).
    expect(StgWooProduct::where('woo_id', 10)->first()->status_triagem)
        ->toBe(DestinoDaTriagem::Involucro->value)
        ->and(StgWooProduct::whereIn('woo_id', [101, 102])->pluck('status_triagem')->unique()->all())
        ->toBe([DestinoDaTriagem::Produto->value]);
});

it('faz o anúncio virar produto quando a variação está vazia', function () {
    // Os 21 casos reais: o anúncio declara cor como eixo e nunca
    // materializou as variações. Quem carrega nome e anúncio é o pai.
    stg(20, 'variable', ['name' => 'Incensário — várias cores']);
    stg(201, 'variation', [], pai: 20);

    app(TriageWooProducts::class)->executar();

    expect(StgWooProduct::where('woo_id', 20)->first()->status_triagem)
        ->toBe(DestinoDaTriagem::Produto->value)
        ->and(StgWooProduct::where('woo_id', 201)->first()->status_triagem)
        ->toBe(DestinoDaTriagem::VariacaoVazia->value);
});

it('só propõe SKU para o que vira produto', function () {
    stg(1, 'simple');
    stg(10, 'variable');
    stg(101, 'variation', atributoDeKit('BUDA'), pai: 10);

    app(TriageWooProducts::class)->executar();

    expect(StgWooProduct::whereNotNull('sku_proposto')->count())->toBe(2)
        ->and(StgWooProduct::where('woo_id', 10)->first()->sku_proposto)->toBeNull();
});

it('propõe SKUs sequenciais em ordem estável', function () {
    stg(30, 'simple');
    stg(10, 'simple');
    stg(20, 'simple');

    app(TriageWooProducts::class)->executar();

    // Ordenado por `woo_id`, não por ordem de inserção: rodar de novo tem
    // de propor os mesmos códigos, senão as conferências já feitas viram
    // lixo.
    expect(StgWooProduct::where('woo_id', 10)->first()->sku_proposto)->toBe('DA-0001')
        ->and(StgWooProduct::where('woo_id', 20)->first()->sku_proposto)->toBe('DA-0002')
        ->and(StgWooProduct::where('woo_id', 30)->first()->sku_proposto)->toBe('DA-0003');
});

it('não renumera ao rodar a triagem de novo', function () {
    stg(10, 'simple');
    $triagem = app(TriageWooProducts::class);

    $triagem->executar();
    $primeiro = StgWooProduct::first()->sku_proposto;

    stg(20, 'simple');
    $triagem->executar();

    // O SKU é imutável (BR-002). Se a proposta mudasse entre execuções,
    // ele seria imutável só no nome.
    expect(StgWooProduct::where('woo_id', 10)->first()->sku_proposto)->toBe($primeiro)
        ->and(StgWooProduct::where('woo_id', 20)->first()->sku_proposto)->toBe('DA-0002');
});

it('continua a sequência a partir dos produtos que já existem', function () {
    Product::factory()->create(['sku' => 'DA-0007']);
    stg(10, 'simple');

    app(TriageWooProducts::class)->executar();

    // A migração não é o único caminho de cadastro: se alguém já criou
    // produtos à mão, a proposta não pode colidir com eles.
    expect(StgWooProduct::first()->sku_proposto)->toBe('DA-0008');
});

it('deixa tudo pendente de aprovação', function () {
    stg(10, 'simple');

    app(TriageWooProducts::class)->executar();

    // A carga (F4) só aceita aprovado. A conferência humana acontece
    // antes de o código existir, não depois.
    expect(StgWooProduct::first()->aprovado_em)->toBeNull();
});

it('não carrega nada no catálogo', function () {
    stg(10, 'simple');

    app(TriageWooProducts::class)->executar();

    expect(Product::query()->count())->toBe(0);
});

it('registra o motivo de cada classificação', function () {
    stg(10, 'variable');
    stg(101, 'variation', atributoDeKit('BUDA'), pai: 10);

    app(TriageWooProducts::class)->executar();

    // A listagem de triagem precisa explicar sem exigir ler código.
    expect(StgWooProduct::where('woo_id', 10)->first()->motivo_triagem)
        ->toContain('variações reais');
});
