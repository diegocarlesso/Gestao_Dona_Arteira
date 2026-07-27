<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use DomainException;

class ContagemInvalida extends DomainException
{
    /**
     * BR-205: aprovador ≠ contador.
     *
     * A razão de a contagem ser uma entidade e não um formulário de
     * ajuste em lote. Quem conta registra o que viu; outra pessoa confere
     * a divergência antes de o ajuste virar movimento. Contagem que a
     * mesma pessoa lança e aprova não é controle — é digitação com etapa
     * extra.
     */
    public static function aprovadorEhOContador(): self
    {
        return new self('Quem contou não pode aprovar a própria contagem (BR-205).');
    }

    public static function jaExisteContagemAberta(string $local): self
    {
        // Duas contagens abertas no mesmo local produziriam ajustes
        // calculados sobre o mesmo saldo congelado, e a segunda desfaria
        // a primeira sem que ninguém percebesse.
        return new self("Já existe uma contagem aberta em {$local}. Encerre-a antes de abrir outra.");
    }

    public static function statusNaoPermite(string $status, string $acao): self
    {
        return new self("Contagem {$status} não aceita {$acao}.");
    }

    public static function semItemContado(): self
    {
        return new self('Nenhum item foi contado — não há o que aprovar.');
    }
}
