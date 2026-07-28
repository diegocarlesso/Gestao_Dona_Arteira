<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Models\Order;

/**
 * Cancela, por id, um pedido cujo cancelamento veio do canal — sync-pedidos §5.
 *
 * A superfície que a Integração chama quando o site avisa que o pedido foi
 * cancelado/reembolsado (`order.updated`): recebe o **id** e devolve um
 * **primitivo**, para a Integração não tocar `Order` (ADR-0020).
 *
 * Resolve os casos que a regra de "quem vence" (docs/16 §3) impõe:
 * - já cancelado no ERP → nada a fazer (anti-eco: foi o próprio ERP que
 *   pediu, o Woo ecoou de volta);
 * - já **expedido/entregue** → conflito: a peça saiu, o Woo não desfaz
 *   isso; vira alerta, não cancelamento (desfazer é devolução);
 * - cancelável → cancela liberando a reserva, marcado como
 *   `originadoNoCanal` para não empurrar o eco de volta ao Woo.
 */
class CancelChannelOrderService
{
    public const CANCELADO = 'cancelado';

    public const JA_CANCELADO = 'ja_cancelado';

    public const CONFLITO_EXPEDIDO = 'conflito_expedido';

    public const NAO_ENCONTRADO = 'nao_encontrado';

    public function __construct(private readonly CancelOrderService $cancelamento) {}

    public function cancelarPorId(int $orderId, string $motivo): string
    {
        $pedido = Order::query()->find($orderId);

        if ($pedido === null) {
            return self::NAO_ENCONTRADO;
        }

        if ($pedido->status === OrderStatus::Cancelled) {
            return self::JA_CANCELADO;
        }

        if (! $pedido->status->cancelavel()) {
            return self::CONFLITO_EXPEDIDO;
        }

        $this->cancelamento->handle($pedido, $motivo, originadoNoCanal: true);

        return self::CANCELADO;
    }
}
