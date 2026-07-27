<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use DomainException;

/**
 * O movimento não chega a ser tentado — falta algo que o torna
 * interpretável depois.
 */
class MovimentoInvalido extends DomainException
{
    public static function quantidadeNaoPositiva(string $quantidade): self
    {
        // A quantidade chega sempre positiva; o sinal vem do tipo
        // (`MovementType::sinal()`). Aceitar negativo aqui criaria dois
        // jeitos de registrar uma saída, e um deles inverteria o outro.
        return new self("Quantidade precisa ser maior que zero, recebido {$quantidade}.");
    }

    public static function motivoObrigatorio(string $tipo): self
    {
        // Ajuste e perda existem porque algo saiu do previsto (BR-205).
        // Sem motivo, o extrato mostra que o saldo mudou e não diz por
        // quê — que é a única pergunta que o ledger existe para responder.
        return new self("O movimento do tipo {$tipo} exige motivo.");
    }
}
