<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Listeners;

use App\Modules\Fiscal\Jobs\EmitNfeForOrder;
use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Events\OrderConfirmed;

/**
 * Pedido do site confirmado → NF-e emitida sem passo manual — ADR-0025 §2.
 *
 * O requisito que a diretoria pediu (P0.3), e que quase não custou
 * infraestrutura nova: o pipeline Woo→ERP já existe em produção e termina
 * disparando `OrderConfirmed`. Bastou ouvir.
 *
 * Mesmo desenho de `Integrations\WooCommerce\Listeners\EnviarExpedicaoAoWoo`:
 * enxerga só o **Event** e o **Enum** de Vendas — nunca `Sales\Models\Order`
 * (fronteira verificada no CI) —, lê os primitivos do pedido e delega o
 * trabalho ao job em fila. A emissão nunca trava a resposta do webhook nem
 * a confirmação do pedido (BR-705).
 *
 * **Filtro de canal:** só o pedido do site neste corte. Não é limitação
 * técnica — é que a BR-601 ("quando a NF-e é exigida") ainda é hipótese
 * bloqueada no contador, e a venda de balcão é o caso onde a resposta mais
 * provavelmente é "depende". Quando a regra for validada, balcão e atacado
 * entram removendo esta guarda; o gatilho é o mesmo.
 *
 * **Flag `nfe.enabled`:** governa o gatilho *automático*. Desligada, o
 * pedido segue seu fluxo normal e nenhuma nota é enfileirada — que é o
 * estado correto num ambiente sem certificado, sem perfis fiscais e sem
 * pré-flight. Emissão manual/reprocesso despacha o job direto e não passa
 * por aqui.
 */
class EmitirNfeAoConfirmarPedido
{
    public function handle(OrderConfirmed $event): void
    {
        if (! config('fiscal.nfe.enabled')) {
            return;
        }

        $pedido = $event->pedido;

        if ($pedido->channel !== OrderChannel::WooCommerce) {
            return;
        }

        EmitNfeForOrder::dispatch($pedido->id);
    }
}
