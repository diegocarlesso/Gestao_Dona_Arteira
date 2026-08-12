<?php

declare(strict_types=1);

namespace App\Modules\Integrations\MercadoPago\Exceptions;

use RuntimeException;

/**
 * A integração com o Mercado Pago não pôde ser usada — docs/15-Integracoes,
 * ADR-0018.
 *
 * Mensagens dizem o que fazer, mesmo padrão de `IntegracaoWooIndisponivel`.
 * Quem captura esta exceção (`MercadoPagoGateway`) sempre a converte em
 * `App\Modules\Finance\Exceptions\CobrancaGatewayIndisponivel` antes de
 * devolver ao Financeiro — este tipo nunca atravessa a fronteira do módulo.
 */
class IntegracaoMercadoPagoIndisponivel extends RuntimeException
{
    public static function desligada(): self
    {
        return new self(
            'A integração com o Mercado Pago está desligada. '
            .'Defina MERCADOPAGO_ENABLED=true no .env e rode `php artisan config:cache`.'
        );
    }

    public static function semCredencial(string $campo): self
    {
        $variavel = 'MERCADOPAGO_'.mb_strtoupper($campo);

        return new self(
            "Falta a configuração `{$variavel}` no .env. "
            .'O Access Token é gerado no painel do desenvolvedor do Mercado Pago → Credenciais.'
        );
    }

    public static function resposta(int $status, string $corpo): self
    {
        $dica = match (true) {
            $status === 401 => ' Access Token incorreto ou expirado.',
            $status === 400 => ' Dado do pagamento rejeitado pelo Mercado Pago — confira os campos enviados.',
            $status >= 500 => ' O erro é do lado do Mercado Pago, não daqui. Tentar de novo mais tarde costuma resolver.',
            default => '',
        };

        return new self(
            "O Mercado Pago respondeu {$status}.{$dica} Resposta: ".mb_substr($corpo, 0, 300)
        );
    }
}
