<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

/**
 * A "impressão" de um pedido do Woo para a reconciliação — ADR-0025, docs/16 §5.
 *
 * Para pedido, a impressão é o **total canônico** (BR-304 congela o valor na
 * importação; BR-703 diz que o total é a origem do fato). Guardamos o valor,
 * não um hash: assim o painel mostra "de R$ X para R$ Y" e o `resolver`
 * re-baseliza com um número legível. Um dia, reconciliação multi-campo
 * (estoque/produto) pode hashear uma representação canônica — para o total
 * único, o próprio valor basta e é auditável.
 *
 * Canonicaliza **sem passar por float** (regra 6 do projeto: dinheiro nunca em
 * float): `"200"`, `"200.0"` e `"200.00"` viram todos `"200.00"`.
 */
final class OrderChecksum
{
    /**
     * @param  array<string, mixed>  $order  payload cru do pedido (REST v3)
     */
    public static function of(array $order): string
    {
        $total = (string) ($order['total'] ?? '0');

        // bcadd com escala 2 normaliza para DECIMAL(15,2) sem tocar em float.
        return bcadd($total === '' ? '0' : $total, '0', 2);
    }
}
