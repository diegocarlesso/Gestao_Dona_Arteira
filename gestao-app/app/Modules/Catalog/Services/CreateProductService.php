<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Events\ProductCreated;
use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra um produto e lhe dá o SKU — docs/32-Catalogo, BR-002.
 *
 * O SKU nasce aqui e nunca mais muda. Tudo numa transação porque gerar o
 * código e gravá-lo têm de ser um ato só: entre ler o último número e
 * escrever o próximo, um cadastro concorrente pegaria o mesmo.
 */
class CreateProductService
{
    public function __construct(
        private readonly GenerateSku $sku,
        private readonly SetProductPriceService $precos,
    ) {}

    /**
     * @param  array<string, mixed>  $atributos
     */
    public function handle(array $atributos, ?string $precoVarejo = null, ?string $precoAtacado = null): Product
    {
        return DB::transaction(function () use ($atributos, $precoVarejo, $precoAtacado): Product {
            $produto = new Product($atributos);
            $produto->sku = $this->sku->proximo();
            $produto->status = ProductStatus::Active;
            $produto->save();

            // Preço é opcional no cadastro. O de atacado quase sempre virá
            // depois: a empresa vende nos dois canais, mas os preços de
            // atacado nunca estiveram em sistema e a equipe os preencherá
            // produto a produto (pasta 32 §3.5).
            if ($precoVarejo !== null) {
                $this->precos->handle($produto, PriceList::Retail, $precoVarejo);
            }

            if ($precoAtacado !== null) {
                $this->precos->handle($produto, PriceList::Wholesale, $precoAtacado);
            }

            ProductCreated::dispatch($produto);

            return $produto->refresh();
        });
    }
}
