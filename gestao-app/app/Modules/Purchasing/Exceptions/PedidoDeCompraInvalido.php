<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Exceptions;

use DomainException;

class PedidoDeCompraInvalido extends DomainException
{
    public static function naoEditavel(string $status): self
    {
        // Só o rascunho aceita edição. Enviado já foi ao fornecedor e
        // confirmado já virou dívida — mudar itens sem passar pelo
        // financeiro deixaria o título valendo outro valor que o pedido.
        return new self("Pedido de compra {$status} não pode mais ser editado.");
    }

    public static function semItens(): self
    {
        return new self('Não dá para registrar um pedido de compra sem itens.');
    }

    public static function semValor(): self
    {
        // O título a pagar recusa valor não positivo (BR-501), e deixar a
        // exceção estourar lá deixaria a mensagem falando de financeiro
        // para quem está montando uma compra. A regra é a mesma; o lugar
        // de dizê-la é onde o operador digitou.
        return new self('O pedido de compra precisa de ao menos um item com valor — confira quantidades e custos.');
    }

    public static function produtoDesconhecido(string $publicId): self
    {
        return new self("O produto {$publicId} não existe no catálogo — atualize a tela e escolha o item de novo.");
    }

    public static function transicaoInvalida(string $de, string $para): self
    {
        return new self("Pedido de compra {$de} não pode ir para {$para}.");
    }

    public static function confirmadoNaoCancela(int $numero): self
    {
        // O PC confirmado já gerou conta a pagar (BR-402). Cancelá-lo
        // apagaria o pedido e deixaria a dívida — ou, pior, apagaria os
        // dois e o dono deixaria de pagar o que deve. O acerto é
        // contrapartida no Financeiro (BR-504, Gate 04).
        return new self(
            "O PC #{$numero} já foi confirmado e gerou conta a pagar — cancelar exige acerto no Financeiro, "
            .'que ainda não existe nesta fase. Combine a devolução com o fornecedor e registre o estorno quando o Gate 04 entrar.'
        );
    }
}
