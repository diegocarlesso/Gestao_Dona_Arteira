<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTO;

/**
 * O que outro módulo precisa saber de um produto para exibi-lo.
 *
 * Existe porque o ADR-0020 proíbe referenciar `Catalog\Models` de fora, e
 * a proibição só é utilizável se houver **algo nomeável** para o Service
 * devolver: uma tela de estoque precisa escrever "DA-0413 · Buda sidarta
 * 11 cm" ao lado do saldo, e devolver `array` solto empurraria o
 * acoplamento para dentro de chaves de string que ninguém verifica.
 *
 * Deliberadamente magro. Não é o produto — é o cartão de visita dele.
 * Quem precisar de preço, medida ou categoria pede ao Catálogo outra
 * coisa; alargar este DTO até virar cópia do model desfaria a fronteira
 * por dentro.
 */
final readonly class ProductSummary
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $sku,
        public string $name,
        public ?string $color,
        public string $unit,
        public ?string $minStock,
    ) {}
}
