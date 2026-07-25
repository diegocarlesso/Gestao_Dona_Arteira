<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use DomainException;

/**
 * Tentou-se alterar o SKU de um produto já criado — BR-002.
 *
 * O código é a chave de casamento com pedido, movimento de estoque e nota
 * fiscal. Trocá-lo depois não renomeia um rótulo: quebra a ligação com
 * tudo que já o referenciou.
 */
class SkuEhImutavel extends DomainException
{
    public static function para(string $de, string $para): self
    {
        return new self(
            "O SKU é imutável após a criação (BR-002): tentou-se mudar de {$de} para {$para}."
        );
    }
}
