<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Enums\ProductKind;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Services\ArchiveProductService;
use App\Modules\Catalog\Services\CreateProductService;
use App\Modules\Catalog\Services\SetProductPriceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cadastro do catálogo (pasta 32).
 *
 * Controller fino (ADR-0015): autoriza pela Policy, valida pelo
 * FormRequest, delega ao Service, responde com Inertia.
 */
class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $busca = $request->string('busca')->trim()->value();
        $pendencia = $request->string('pendencia')->trim()->value();

        $produtos = Product::query()
            ->with(['category:id,name', 'prices'])
            ->when($busca !== '', fn ($q) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$busca}%")
                    // Busca por SKU **e** por nome porque o código é
                    // neutro (ADR-0022): ninguém decora `DA-0413`, mas
                    // quem tem a etiqueta na mão digita exatamente isso.
                    ->orWhere('sku', 'like', "%{$busca}%")
                    ->orWhere('color', 'like', "%{$busca}%")
            ))
            // O filtro que a equipe usa para saber o que falta preencher
            // sem varrer 716 fichas (pasta 32 §3.5).
            ->when($pendencia === 'sem_atacado', fn ($q) => $q->semPrecoDe(PriceList::Wholesale))
            ->when($pendencia === 'sem_varejo', fn ($q) => $q->semPrecoDe(PriceList::Retail))
            ->when($pendencia === 'sem_peso', fn ($q) => $q->whereNull('weight_g'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Product $p): array => $this->paraTela($p));

        return Inertia::render('catalog/products/index', [
            'produtos' => $produtos,
            'filtros' => ['busca' => $busca, 'pendencia' => $pendencia],
            'contagemPendencias' => [
                'sem_atacado' => Product::query()->active()->semPrecoDe(PriceList::Wholesale)->count(),
                'sem_varejo' => Product::query()->active()->semPrecoDe(PriceList::Retail)->count(),
                'sem_peso' => Product::query()->active()->whereNull('weight_g')->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('catalog/products/create', $this->opcoesDeFormulario());
    }

    public function store(StoreProductRequest $request, CreateProductService $service): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $produto = $service->handle(
            $request->safe()->except(['preco_varejo', 'preco_atacado']),
            $request->string('preco_varejo')->value() ?: null,
            $request->string('preco_atacado')->value() ?: null,
        );

        return to_route('catalog.products.edit', $produto)
            ->with('sucesso', "Produto criado com o código {$produto->sku}.");
    }

    public function edit(Product $product): Response
    {
        $this->authorize('view', $product);

        $product->load(['category:id,name', 'defaultPackage:id,name', 'prices']);

        return Inertia::render('catalog/products/edit', [
            'produto' => $this->paraTela($product) + [
                'description' => $product->description,
                'unit' => $product->unit,
                'height_cm' => $product->height_cm,
                'width_cm' => $product->width_cm,
                'depth_cm' => $product->depth_cm,
                'ncm' => $product->ncm,
                'cest' => $product->cest,
                'origin' => $product->origin,
                'gtin' => $product->gtin,
                'product_category_id' => $product->product_category_id,
                'default_package_id' => $product->default_package_id,
                'min_stock' => $product->min_stock,
                'drying_days' => $product->drying_days,
                'historicoDePrecos' => $product->prices
                    ->sortByDesc('valid_from')
                    ->values()
                    ->map(fn ($p): array => [
                        'lista' => $p->price_list->label(),
                        'preco' => $p->price,
                        'desde' => $p->valid_from->toIso8601String(),
                    ])->all(),
            ],
        ] + $this->opcoesDeFormulario());
    }

    public function update(StoreProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        // `sku` não está no $fillable nem nas regras do FormRequest — a
        // imutabilidade da BR-002 é garantida em três camadas, e o model
        // lança se alguém chegar lá por outro caminho.
        $product->update($request->safe()->except(['preco_varejo', 'preco_atacado']));

        return to_route('catalog.products.edit', $product)
            ->with('sucesso', 'Produto atualizado.');
    }

    /**
     * Define preço de uma lista — sempre linha nova, nunca UPDATE.
     */
    public function setPrice(Request $request, Product $product, SetProductPriceService $service): RedirectResponse
    {
        $this->authorize('setPrice', $product);

        $validado = $request->validate([
            'lista' => ['required', 'string', 'in:retail,wholesale'],
            'preco' => ['required', 'decimal:0,2', 'min:0'],
        ], [], ['preco' => 'preço']);

        $service->handle(
            $product,
            PriceList::from($validado['lista']),
            $validado['preco'],
        );

        return back()->with('sucesso', 'Preço registrado.');
    }

    public function archive(Product $product, ArchiveProductService $service): RedirectResponse
    {
        $this->authorize('archive', $product);

        $product->status === ProductStatus::Active
            ? $service->arquivar($product)
            : $service->desarquivar($product);

        return back()->with(
            'sucesso',
            $product->refresh()->status === ProductStatus::Archived
                ? 'Produto arquivado — some das telas de venda e do site, e continua no histórico.'
                : 'Produto reativado.',
        );
    }

    /**
     * @return array{
     *     tipos: list<array{value: string, label: string}>,
     *     categorias: list<array{id: int, nome: string}>,
     *     embalagens: list<array{id: int, nome: string}>
     * }
     */
    private function opcoesDeFormulario(): array
    {
        return [
            'tipos' => array_map(
                static fn (ProductKind $k): array => ['value' => $k->value, 'label' => $k->label()],
                ProductKind::cases(),
            ),
            'categorias' => ProductCategory::query()
                ->orderBy('name')
                ->get()
                ->map(fn (ProductCategory $c): array => ['id' => $c->id, 'nome' => $c->caminho()])
                ->all(),
            'embalagens' => Package::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Package $p): array => ['id' => $p->id, 'nome' => $p->name])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paraTela(Product $produto): array
    {
        return [
            'public_id' => $produto->public_id,
            'sku' => $produto->sku,
            'name' => $produto->name,
            'color' => $produto->color,
            'kind' => $produto->kind->label(),
            'status' => $produto->status->value,
            'categoria' => $produto->category?->name,
            'weight_g' => $produto->weight_g,
            'sell_on_woo' => $produto->sell_on_woo,
            'preco_varejo' => $produto->precoVigente(PriceList::Retail)?->price,
            'preco_atacado' => $produto->precoVigente(PriceList::Wholesale)?->price,
            // A lacuna vai para a tela em vez de ficar em silêncio: é o
            // que evita descobrir na hora da venda (pasta 32 §3.6).
            'pendencias' => $produto->pendencias(),
        ];
    }
}
