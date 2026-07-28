<?php

declare(strict_types=1);

namespace App\Modules\Sales\Exceptions;

use DomainException;

class PedidoInvalido extends DomainException
{
    public static function naoEditavel(string $status): self
    {
        // Só o rascunho aceita edição de itens/cliente. Confirmado já
        // reservou estoque; mudar itens sem passar pela reserva
        // dessincronizaria o pedido do que está prometido.
        return new self("Pedido {$status} não pode mais ser editado.");
    }

    public static function semItens(): self
    {
        return new self('Não dá para confirmar um pedido sem itens.');
    }

    public static function produtoSemPreco(string $sku): self
    {
        // Não se vende o que não tem preço. A carga deixou 21 produtos sem
        // preço de varejo; a tela de catálogo os sinaliza, e é lá que se
        // resolve antes de vender.
        return new self("O produto {$sku} não tem preço de varejo — defina o preço antes de vendê-lo.");
    }

    public static function transicaoInvalida(string $de, string $para): self
    {
        return new self("Pedido {$de} não pode ir para {$para}.");
    }
}
