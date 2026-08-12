<?php

declare(strict_types=1);

namespace App\Modules\Finance\Exceptions;

use RuntimeException;

/**
 * O provedor de cobrança não pôde registrar/cancelar a cobrança.
 *
 * Cobre tanto "gateway ainda não configurado" (`NullCobrancaGateway`)
 * quanto falha real do provedor (rede, credencial, validação de dados do
 * pagador) — em ambos os casos, quem chama (`EmitirCobrancaService`)
 * captura e marca a cobrança como `failed` com o motivo, nunca deixa
 * subir como erro 500 (docs/12-Financeiro/01-cobranca-e-boletos.md §3,
 * ramo "R -- falha").
 */
class CobrancaGatewayIndisponivel extends RuntimeException
{
    public static function naoConfigurado(string $motivo): self
    {
        return new self($motivo);
    }

    public static function falhaDoProvedor(string $motivo): self
    {
        return new self($motivo);
    }
}
