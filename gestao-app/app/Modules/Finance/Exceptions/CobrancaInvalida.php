<?php

declare(strict_types=1);

namespace App\Modules\Finance\Exceptions;

use DomainException;

/**
 * Erros de regra da cobrança em si — distinto de `TituloInvalido` (que é
 * sobre o título a receber por trás dela).
 */
class CobrancaInvalida extends DomainException
{
    public static function tituloJaQuitado(int $receivableId): self
    {
        return new self("O título {$receivableId} já está quitado; não há saldo para gerar cobrança.");
    }

    public static function jaExisteCobrancaAtiva(int $receivableId): self
    {
        // BR-506: cobrança registrada é imutável — mudar é cancelar e
        // reemitir, nunca ter duas cobranças correndo ao mesmo tempo para
        // o mesmo título (o cliente poderia pagar as duas).
        return new self(
            "O título {$receivableId} já tem uma cobrança ativa (pendente ou registrada). "
            .'Cancele-a antes de emitir outra.'
        );
    }

    public static function enderecoDoPagadorObrigatorio(): self
    {
        // ADR-0018 §3.1: boleto exige endereço completo do pagador; PIX
        // com vencimento não.
        return new self('Boleto exige o endereço completo do pagador (CEP, rua, número, bairro, cidade, UF).');
    }

    public static function cobrancaInexistente(string $publicId): self
    {
        return new self("A cobrança {$publicId} não existe.");
    }

    public static function naoCancelavel(string $status): self
    {
        return new self("Cobrança com status \"{$status}\" não pode ser cancelada — já está num estado final.");
    }
}
