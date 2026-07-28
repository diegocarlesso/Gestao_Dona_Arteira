<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTO;

/**
 * O estado atual de um produto, para a validação da migração (F5).
 *
 * Separado do `ProductSummary` de propósito: aquele é o cartão de visita
 * para exibir o produto noutro módulo; este carrega os campos que a
 * conferência da carga precisa comparar com a origem — peso, preço de
 * varejo e categoria. Alargar o `ProductSummary` até caber os dois
 * misturaria duas intenções num objeto só.
 */
final readonly class ProductSnapshot
{
    public function __construct(
        public string $sku,
        public string $name,
        public ?string $color,
        public ?string $weightG,
        public ?string $retailPrice,
        public ?string $categoryName,
    ) {}
}
