<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services\Concerns;

use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Exceptions\PedidoDeCompraInvalido;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderStatusHistory;

/**
 * A transição de estado do PC, com a trilha — BR-402.
 *
 * Vendas repete este mesmo par (`exigirStatus` + `transicionar`) em dois
 * Services e sobrevive; aqui as transições são três (enviar, confirmar,
 * cancelar), e a terceira cópia é justamente onde uma delas esquece de
 * gravar o histórico — o defeito que só aparece quando alguém pergunta
 * "quem confirmou este PC?" e a resposta não existe mais.
 *
 * Mora em `Services/` de propósito: quem transiciona é o caso de uso
 * (ADR-0015), não o model — o estado muda por uma decisão de negócio com
 * autor, nunca por atribuição solta.
 */
trait TransicionaPedidoDeCompra
{
    /**
     * Grava a trilha e move o estado. Sempre dentro da transação de quem
     * chama, para que a linha do histórico e o novo status caiam juntos.
     */
    private function transicionar(
        PurchaseOrder $pedido,
        PurchaseOrderStatus $novo,
        ?int $usuario = null,
        ?string $motivo = null,
    ): void {
        PurchaseOrderStatusHistory::query()->create([
            'purchase_order_id' => $pedido->id,
            'from_status' => $pedido->status,
            'to_status' => $novo,
            'reason' => $motivo,
            'created_by' => $usuario,
            'created_at' => now(),
        ]);

        $pedido->status = $novo;
        $pedido->save();
    }

    /**
     * @throws PedidoDeCompraInvalido
     */
    private function exigirStatus(PurchaseOrder $pedido, PurchaseOrderStatus $esperado, PurchaseOrderStatus $destino): void
    {
        if ($pedido->status !== $esperado) {
            throw PedidoDeCompraInvalido::transicaoInvalida($pedido->status->label(), $destino->label());
        }
    }
}
