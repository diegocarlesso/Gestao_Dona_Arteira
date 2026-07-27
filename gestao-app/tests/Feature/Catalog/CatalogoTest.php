<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Enums\ProductKind;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\SkuEhImutavel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ArchiveProductService;
use App\Modules\Catalog\Services\CreateProductService;
use App\Modules\Catalog\Services\GenerateSku;
use App\Modules\Catalog\Services\ImportProductImagesService;
use App\Modules\Catalog\Services\SetProductPriceService;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\Identity\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Catálogo — docs/32-Catalogo, ADR-0022
|--------------------------------------------------------------------------
|
| As invariantes que o módulo promete: SKU único, sequencial e imutável
| (BR-002); cada acabamento é um produto próprio (BR-009); preço é
| histórico, não estado (BR-003); produto não se exclui (BR-008).
|
*/

function admin(): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum(Role::Admin);
}

/** @return array<string, mixed> */
function atributosDeProduto(array $extra = []): array
{
    // `->value` e não o enum: o mesmo array alimenta o Service e um POST
    // HTTP, e o InputBag do Symfony só aceita escalares.
    return array_merge([
        'name' => 'Buda sidarta 11 cm',
        'kind' => ProductKind::FinishedGood->value,
        'unit' => 'UN',
        'weight_g' => '450',
    ], $extra);
}

// ------------------------------------------------------------------ SKU

it('gera SKU sequencial no formato DA-0001', function () {
    $service = app(CreateProductService::class);

    $primeiro = $service->handle(atributosDeProduto(['name' => 'Peça A']));
    $segundo = $service->handle(atributosDeProduto(['name' => 'Peça B']));

    expect($primeiro->sku)->toBe('DA-0001')
        ->and($segundo->sku)->toBe('DA-0002');
});

it('passa de quatro para cinco dígitos sem quebrar a sequência', function () {
    Product::factory()->create(['sku' => 'DA-9999']);

    // A ordenação é por LENGTH primeiro: um `ORDER BY sku DESC` textual
    // colocaria 'DA-9999' à frente de 'DA-10000' e a sequência voltaria
    // para trás, gerando SKU duplicado.
    expect(app(GenerateSku::class)->proximo())->toBe('DA-10000');

    Product::factory()->create(['sku' => 'DA-10000']);
    expect(app(GenerateSku::class)->proximo())->toBe('DA-10001');
});

it('recusa alterar o SKU depois de criado', function () {
    $produto = app(CreateProductService::class)->handle(atributosDeProduto());

    $produto->sku = 'DA-9999';

    // BR-002: o código é a chave de casamento com pedido, movimento e
    // nota fiscal. Trocá-lo não renomeia um rótulo — rompe a ligação com
    // tudo que já o referenciou.
    expect(fn () => $produto->save())->toThrow(SkuEhImutavel::class);
});

it('não aceita SKU vindo de formulário', function () {
    $produto = new Product(['sku' => 'HACK-0001', 'name' => 'Tentativa', 'kind' => ProductKind::FinishedGood]);

    expect($produto->sku)->toBeNull();
});

// ------------------------------------------------- variação é produto

it('trata cada acabamento como produto próprio, com SKU e saldo próprios', function () {
    $service = app(CreateProductService::class);

    $azul = $service->handle(atributosDeProduto(['color' => 'Azul']));
    $dourado = $service->handle(atributosDeProduto(['color' => 'Dourado']));

    // BR-009: a cor é acabamento de pintura manual, produzida
    // separadamente e ocupando lugar próprio na prateleira.
    expect($azul->sku)->not->toBe($dourado->sku)
        ->and($azul->name)->toBe($dourado->name)
        // Mesmo nome, slugs distintos — senão o UNIQUE reprovaria o
        // segundo cadastro com uma mensagem que não explica nada.
        ->and($azul->slug)->not->toBe($dourado->slug);
});

// --------------------------------------------------------------- preços

it('registra preço como linha nova, preservando o anterior', function () {
    $produto = app(CreateProductService::class)->handle(atributosDeProduto(), precoVarejo: '89.90');
    $service = app(SetProductPriceService::class);

    $service->handle($produto, PriceList::Retail, '99.90');

    // Um pedido de seis meses atrás precisa continuar explicável: sem a
    // linha antiga não há como provar por quanto a peça foi vendida.
    expect($produto->prices()->where('price_list', 'retail')->count())->toBe(2)
        ->and($produto->precoVigente(PriceList::Retail)->price)->toBe('99.90');
});

it('não deixa editar nem apagar uma linha de preço', function () {
    $produto = app(CreateProductService::class)->handle(atributosDeProduto(), precoVarejo: '50.00');
    $preco = $produto->precoVigente(PriceList::Retail);

    $preco->price = '10.00';
    $preco->save();
    $preco->delete();

    expect($produto->refresh()->precoVigente(PriceList::Retail)->price)->toBe('50.00')
        ->and($produto->prices()->count())->toBe(1);
});

it('aceita produto sem preço de atacado', function () {
    $produto = app(CreateProductService::class)->handle(atributosDeProduto(), precoVarejo: '89.90');

    // A empresa vende atacado, mas os preços nunca estiveram em sistema e
    // a equipe os preencherá produto a produto (pasta 32 §3.5). Nulo é
    // resposta legítima, não erro.
    expect($produto->temPrecoDe(PriceList::Retail))->toBeTrue()
        ->and($produto->temPrecoDe(PriceList::Wholesale))->toBeFalse()
        ->and($produto->pendencias())->toContain('sem preço de atacado');
});

it('recusa preço com mais de duas casas decimais', function () {
    actingAs(admin())
        ->post('/produtos', atributosDeProduto(['preco_varejo' => '89.999']))
        ->assertSessionHasErrors('preco_varejo');
});

// ------------------------------------------------------- arquivamento

it('arquiva e reativa sem excluir', function () {
    $produto = app(CreateProductService::class)->handle(atributosDeProduto());
    $service = app(ArchiveProductService::class);

    $service->arquivar($produto);
    expect($produto->refresh()->status)->toBe(ProductStatus::Archived);

    $service->desarquivar($produto);
    expect($produto->refresh()->status)->toBe(ProductStatus::Active);
});

it('nega exclusão de produto até para o admin', function () {
    $produto = app(CreateProductService::class)->handle(atributosDeProduto());

    // BR-008. Sem `delete` na lista SEMPRE_PELA_POLICY do Identity, o
    // atalho `Gate::before` do admin responderia `true` aqui e alguém
    // construiria a tela de exclusão que a regra proíbe.
    expect(admin()->can('delete', $produto))->toBeFalse();
});

// --------------------------------------------------------- permissões

it('nega o catálogo a conta sem papel algum', function () {
    // BR-801, negação por padrão. O alvo aqui é conta **sem papel**, e
    // não um papel específico: `catalog.view` está em TODOS os papéis, de
    // propósito — produção, vendas, expedição, financeiro e contador
    // consultam o catálogo o dia inteiro. O que a regra protege é o caso
    // de alguém que ainda não recebeu papel nenhum.
    $semPapel = User::factory()->comDoisFatores()->create();

    actingAs($semPapel)->get('/produtos')->assertForbidden();
});

it('deixa todos os papéis operacionais consultarem o catálogo', function (Role $papel) {
    $usuario = User::factory()->comDoisFatores()->create()->assignRoleEnum($papel);

    actingAs($usuario)->get('/produtos')->assertOk();
})->with([
    'produção' => Role::Production,
    'vendas' => Role::Sales,
    'expedição' => Role::Fulfillment,
    'financeiro' => Role::Finance,
    'contador' => Role::Accountant,
]);

it('deixa ver mas não cadastrar quem só tem catalog.view', function () {
    $producao = User::factory()->comDoisFatores()->create()->assignRoleEnum(Role::Production);

    actingAs($producao)->get('/produtos')->assertOk();
    actingAs($producao)->post('/produtos', atributosDeProduto())->assertForbidden();
});

// ------------------------------------------------------------ listagem

it('filtra os produtos sem preço de atacado', function () {
    $service = app(CreateProductService::class);
    $service->handle(atributosDeProduto(['name' => 'Com atacado']), precoVarejo: '80.00', precoAtacado: '50.00');
    $service->handle(atributosDeProduto(['name' => 'Sem atacado']), precoVarejo: '80.00');

    // É o filtro que evita a equipe varrer 716 fichas para achar o que
    // ainda falta preencher.
    $semAtacado = Product::query()->semPrecoDe(PriceList::Wholesale)->pluck('name');

    expect($semAtacado)->toHaveCount(1)
        ->and($semAtacado->first())->toBe('Sem atacado');
});

it('cadastra um produto pela tela e mostra o código gerado', function () {
    actingAs(admin())
        ->post('/produtos', atributosDeProduto(['preco_varejo' => '89.90']))
        ->assertRedirect()
        ->assertSessionHas('sucesso', fn (string $m): bool => str_contains($m, 'DA-0001'));

    expect(Product::query()->where('sku', 'DA-0001')->exists())->toBeTrue();
});

it('mostra a galeria na ficha do produto', function () {
    $produto = app(CreateProductService::class)->handle(atributosDeProduto());

    app(ImportProductImagesService::class)->importar($produto->id, [
        ['remote_id' => 1, 'url' => 'https://exemplo.test/a.jpg', 'thumbnail_url' => 'https://exemplo.test/a-mini.jpg', 'alt' => 'Frente'],
        ['remote_id' => 2, 'url' => 'https://exemplo.test/b.jpg', 'thumbnail_url' => null, 'alt' => null],
    ]);

    actingAs(admin())
        ->get("/produtos/{$produto->public_id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/products/edit')
            // A galeria inteira, não só a principal: quem confere o
            // cadastro precisa dos ângulos que a origem tem.
            ->has('produto.imagens', 2)
            ->where('produto.imagens.0.previa', 'https://exemplo.test/a-mini.jpg')
            // Sem miniatura, cai para a original — melhor pesada que nenhuma.
            ->where('produto.imagens.1.previa', 'https://exemplo.test/b.jpg')
        );
});

it('expõe a miniatura na listagem, para a prévia do mouse', function () {
    $produto = app(CreateProductService::class)->handle(atributosDeProduto());

    app(ImportProductImagesService::class)->importar($produto->id, [
        ['remote_id' => 1, 'url' => 'https://exemplo.test/a.jpg', 'thumbnail_url' => 'https://exemplo.test/a-mini.jpg'],
    ]);

    actingAs(admin())
        ->get('/produtos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('produtos.data.0.imagem', 'https://exemplo.test/a-mini.jpg')
        );
});

// ------------------------------------------------ ida e volta da ficha

it('salva a ficha de volta sem alterar nada — o que a tela manda, a validação aceita', function () {
    // O defeito que este teste existe para impedir: `paraTela()` mandava
    // `kind` como rótulo ("Produto acabado") porque a mesma função
    // alimenta a listagem. O `select` da edição recebia um valor que não
    // casava com opção alguma, o PUT devolvia o texto, e salvar qualquer
    // edição reprovava com "The selected tipo is invalid" — em produção,
    // na primeira vez que alguém tentou.
    $produto = app(CreateProductService::class)->handle(atributosDeProduto([
        'color' => 'dourado e preto',
        'description' => 'Peça de gesso pintada à mão.',
    ]));

    $pagina = actingAs(admin())->get("/produtos/{$produto->public_id}");
    $daTela = $pagina->viewData('page')['props']['produto'];

    // Exatamente o que o formulário devolve: as chaves que a validação
    // conhece, com os valores que a tela recebeu.
    $camposDoFormulario = [
        'name', 'description', 'kind', 'unit', 'color',
        'height_cm', 'width_cm', 'depth_cm', 'weight_g',
        'ncm', 'cest', 'origin', 'gtin',
        'product_category_id', 'default_package_id',
        'min_stock', 'drying_days', 'sell_on_woo',
    ];

    $payload = array_intersect_key($daTela, array_flip($camposDoFormulario));

    actingAs(admin())
        ->put("/produtos/{$produto->public_id}", $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    // E o produto continua o mesmo: salvar sem mexer não pode mudar nada.
    expect($produto->fresh()->kind)->toBe($produto->kind)
        ->and($produto->fresh()->name)->toBe($produto->name);
});
