<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Preço de uma lista mudou — BR-003.
 *
 * Carrega o de/para porque é isso que uma investigação pergunta: não
 * "quanto custa", que a tabela responde, mas "quanto custava antes e
 * quem mudou". O `laravel-auditing` não cobre: a linha de preço é
 * inserida, não editada, então não há diff a auditar.
 */
class ProductPriceChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Product $produto,
        public readonly PriceList $lista,
        public readonly ?string $de,
        public readonly string $para,
    ) {}
}
