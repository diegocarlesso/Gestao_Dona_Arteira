<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Exceptions;

use RuntimeException;

/**
 * A integração com o WooCommerce não pôde ser usada.
 *
 * Mensagens dizem **o que fazer**, não só o que falhou: quem roda a
 * extração costuma estar num terminal SSH, sem o código à mão.
 */
class IntegracaoWooIndisponivel extends RuntimeException
{
    public static function desligada(): self
    {
        return new self(
            'A integração com o WooCommerce está desligada. '
            .'Defina WOO_ENABLED=true no .env e rode `php artisan config:cache`.'
        );
    }

    public static function semCredencial(string $chave): self
    {
        $variavel = 'WOO_'.mb_strtoupper($chave);

        return new self(
            "Falta a configuração `{$variavel}` no .env. "
            .'As credenciais são geradas em WooCommerce → Configurações → Avançado → REST API; '
            .'permissão de leitura basta para a extração.'
        );
    }

    public static function paginacaoSemFim(string $recurso, int $limite): self
    {
        return new self(
            "A paginação de `{$recurso}` passou de {$limite} páginas sem terminar. "
            .'O endpoint provavelmente está ignorando o parâmetro `page` e devolvendo '
            .'sempre a mesma página — costuma ser plugin do WordPress interferindo na API REST.'
        );
    }

    public static function resposta(string $recurso, int $status, string $corpo): self
    {
        $dica = match (true) {
            $status === 401 => ' Chave ou segredo incorretos, ou o site está bloqueando o cabeçalho de autenticação (comum em Apache: exige SetEnvIf).',
            $status === 404 => ' Confira a URL do site — o caminho esperado é <site>/wp-json/wc/v3/, e a API REST precisa estar habilitada.',
            $status >= 500 => ' O erro é do lado do site, não daqui. Tentar de novo mais tarde costuma resolver.',
            default => '',
        };

        return new self(
            "O WooCommerce respondeu {$status} ao buscar `{$recurso}`.{$dica} "
            .'Resposta: '.mb_substr($corpo, 0, 300)
        );
    }
}
