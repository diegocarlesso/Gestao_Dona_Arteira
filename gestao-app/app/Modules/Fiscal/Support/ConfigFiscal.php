<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Support;

/**
 * Leitura tipada de `config/fiscal.php`.
 *
 * `config()` devolve `mixed`, e praticamente toda chave fiscal nasce
 * vazia de propósito (docs/13 bloqueada no contador). Sem um lugar só
 * para essa leitura, cada classe repetiria o mesmo `is_string() + trim()
 * + '' === null`, e a primeira que esquecesse o `trim` mandaria um espaço
 * em branco para dentro do XML.
 *
 * "Vazio" e "não configurado" são a mesma coisa aqui: `NFE_EMITENTE_CNPJ=`
 * no `.env` é ausência, não string vazia válida.
 */
final class ConfigFiscal
{
    public static function texto(string $chave): ?string
    {
        $valor = config($chave);

        // `int` aceito porque o `.env` de um CRT ou de um CST pode ser
        // lido como número dependendo de como foi escrito.
        if (! is_string($valor) && ! is_int($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * O mesmo, sem pontuação — CNPJ, IE, CEP e código IBGE entram no XML
     * só com dígitos.
     */
    public static function digitos(string $chave): ?string
    {
        $valor = self::texto($chave);

        if ($valor === null) {
            return null;
        }

        $limpo = preg_replace('/\D/', '', $valor) ?? '';

        return $limpo === '' ? null : $limpo;
    }
}
