<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use DomainException;

class ReservaInvalida extends DomainException
{
    public static function disponivelInsuficiente(string $disponivel, string $qty, int $productId): self
    {
        // BR-203/BR-204: reserva-se o disponível (on_hand − reserved), não
        // o físico. Deixar reservar além disso é o oversell que o módulo
        // existe para impedir — duas vendas prometendo a mesma peça.
        return new self(
            "Disponível insuficiente para reservar o produto {$productId}: há {$disponivel} livre e o pedido quer {$qty}."
        );
    }

    public static function semLocalPadrao(): self
    {
        return new self('Não há local de estoque padrão para reservar. Rode o seeder do Ateliê.');
    }

    public static function jaEncerrada(string $status): self
    {
        // Reserva consumida ou liberada não volta atrás: a peça já foi
        // expedida ou já devolveu o número ao disponível.
        return new self("A reserva já está {$status} e não pode mudar.");
    }
}
