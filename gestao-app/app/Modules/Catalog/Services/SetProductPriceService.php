<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Events\ProductPriceChanged;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductPrice;
use Brick\Money\Money;
use Carbon\CarbonInterface;

/**
 * Define o preço de uma lista — BR-003, ADR-0013.
 *
 * **Sempre linha nova**, nunca `UPDATE`. O preço anterior continua no
 * histórico porque um pedido antigo precisa continuar explicável: sem a
 * linha de ontem, não há como provar por quanto a peça foi vendida.
 */
class SetProductPriceService
{
    public function handle(
        Product $produto,
        PriceList $lista,
        string $valor,
        ?CarbonInterface $vigenteDesde = null,
    ): ProductPrice {
        // Passa por `Money` antes de gravar: é ele que recusa "12,5" ou
        // "R$ 12" com uma mensagem de domínio, em vez de o banco truncar
        // em silêncio para 12.00 (ADR-0013).
        $dinheiro = Money::of($valor, 'BRL');

        $anterior = $produto->precoVigente($lista);

        $preco = new ProductPrice([
            'product_id' => $produto->getKey(),
            'price_list' => $lista,
            'price' => (string) $dinheiro->getAmount(),
            'valid_from' => $vigenteDesde ?? now(),
        ]);
        $preco->save();

        ProductPriceChanged::dispatch(
            $produto,
            $lista,
            $anterior?->price,
            $preco->price,
        );

        return $preco;
    }
}
