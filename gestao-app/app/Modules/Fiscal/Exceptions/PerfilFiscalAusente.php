<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Exceptions;

use DomainException;

/**
 * Não há perfil fiscal para o cenário — docs/13 §3.
 *
 * Exceção **própria**, separada da `EmissaoInvalida`, porque a causa e o
 * dono são outros: as demais pendências se resolvem no cadastro de produto
 * ou de cliente, e esta se resolve na matriz `tax_profiles` — que hoje
 * nasce vazia e cuja autoridade é o contador, não a operação.
 *
 * **Isto vai acontecer na primeira emissão de todo ambiente novo**, por
 * construção: a migration cria a tabela vazia de propósito (semear as
 * hipóteses 💡 da doc 13 as faria parecer confirmadas). Não é bug; é o
 * sistema exigindo a decisão fiscal que ainda não foi tomada.
 */
class PerfilFiscalAusente extends DomainException
{
    public static function para(string $operacao, string $destino, string $tipoCliente, string $data): self
    {
        return new self(
            "Nenhum perfil fiscal cadastrado para operação `{$operacao}` × destino `{$destino}` × "
            ."cliente `{$tipoCliente}` vigente em {$data}. Cadastre o CFOP/CSOSN desse cenário em "
            .'`tax_profiles` (docs/13-Fiscal §3) — os valores devem vir do contador, não do palpite.'
        );
    }
}
