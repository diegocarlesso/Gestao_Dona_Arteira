<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Produto cadastrado, com SKU já atribuído (BR-002).
 *
 * Evento é a superfície pública do módulo (ADR-0020): quem quiser
 * publicar no site (pasta 16) ou abrir saldo inicial (pasta 09) ouve
 * isto, sem que o Catálogo precise conhecer nenhum dos dois.
 */
class ProductCreated
{
    use Dispatchable;

    public function __construct(public readonly Product $produto) {}
}
