<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Produto saiu (ou voltou) para o catálogo ativo — BR-008.
 *
 * Interessa à sincronização com o site (pasta 16): arquivar aqui precisa
 * despublicar lá, senão o cliente compra o que não se vende mais.
 */
class ProductArchived
{
    use Dispatchable;

    public function __construct(
        public readonly Product $produto,
        public readonly bool $arquivado,
    ) {}
}
