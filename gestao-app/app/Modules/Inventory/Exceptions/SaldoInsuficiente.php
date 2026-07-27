<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use DomainException;

/**
 * BR-201: saldo de estoque nunca fica negativo.
 *
 * Vale para **todo** tipo de movimento, inclusive ajuste. A BR-201 abre
 * exceção para "ajuste de inventário com permissão específica", e a
 * permissão específica existe (`inventory.adjust`, separada de
 * `inventory.move`) — mas ela decide **quem pode ajustar**, não autoriza
 * saldo negativo: contagem física devolve quantas peças há na prateleira,
 * e prateleira não tem menos que zero peças.
 *
 * Se um dia aparecer caso real que exija furar o piso, o lugar de mudar é
 * aqui, com o motivo registrado no ADR-0008.
 */
class SaldoInsuficiente extends DomainException
{
    public static function para(string $saldoAtual, string $quantidade, int $productId): self
    {
        return new self(
            "Saldo insuficiente para o produto {$productId}: há {$saldoAtual} e a operação retiraria {$quantidade}."
        );
    }
}
