<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\Finance\Exceptions\TituloInvalido;
use App\Modules\Finance\Services\RegisterPayableService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Events\PurchaseOrderConfirmed;
use App\Modules\Purchasing\Exceptions\PedidoDeCompraInvalido;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\Concerns\TransicionaPedidoDeCompra;
use Illuminate\Support\Facades\DB;

/**
 * Confirma o pedido de compra e gera a conta a pagar — BR-402.
 *
 * A transição onde o PC deixa de ser intenção e vira dívida. Na Fase 1
 * (nota de escopo da pasta 11) **é a confirmação que gera o título**, não o
 * recebimento físico: sem módulo de Estoque não há conferência de entrada,
 * e esperar por ela deixaria a compra fora do financeiro justamente no
 * período em que o dono precisa saber quanto deve.
 *
 * Confirma a partir de `Rascunho` **ou** de `Enviado`. Pular o envio é o
 * caso da compra combinada por telefone e registrada depois — o "PC
 * retroativo simplificado" que a pasta 11 §7 pede como mitigação para a
 * compra informal contornar o sistema.
 *
 * **Idempotente pela guarda de estado**: um PC já confirmado não é
 * confirmável (`confirmavel()` só aceita rascunho/enviado), então clicar
 * duas vezes não gera dois títulos. A garantia mora no estado, e não numa
 * consulta ao Financeiro, porque o estado é do Compras — depender da
 * ausência de título no outro módulo inverteria a fronteira do ADR-0020.
 *
 * O título é registrado **dentro da mesma transação** (o
 * `RegisterPayableService` não abre uma própria, de propósito): PC
 * confirmado sem dívida, ou dívida sem PC, são os dois estados que ninguém
 * consegue explicar depois.
 */
class ConfirmPurchaseOrderService
{
    use TransicionaPedidoDeCompra;

    public function __construct(private readonly RegisterPayableService $titulos) {}

    /**
     * A origem gravada no título — o Financeiro guarda o par tipo/id em vez
     * de FK cross-módulo (ADR-0020), e é por esta string que a tela do PC
     * reencontra a dívida que ele gerou.
     */
    public const ORIGEM = 'purchase_order';

    /**
     * @throws PedidoDeCompraInvalido
     * @throws TituloInvalido
     */
    public function handle(PurchaseOrder $pedido, ?int $por = null): PurchaseOrder
    {
        if (! $pedido->status->confirmavel()) {
            throw PedidoDeCompraInvalido::transicaoInvalida(
                $pedido->status->label(),
                PurchaseOrderStatus::Confirmed->label(),
            );
        }

        if ($pedido->items()->count() === 0) {
            throw PedidoDeCompraInvalido::semItens();
        }

        // Conferido antes da transação, e não só no Financeiro: a mensagem
        // de "título sem valor" chegaria falando de contas a pagar para
        // quem está olhando um pedido de compra.
        if (bccomp($pedido->total, '0.00', 2) !== 1) {
            throw PedidoDeCompraInvalido::semValor();
        }

        return DB::transaction(function () use ($pedido, $por): PurchaseOrder {
            // PC retroativo: confirmar sem ter passado pelo envio deixa
            // `issued_at` vazio, e o vencimento precisa contar de algum dia.
            $emissao = $pedido->issued_at ?? now()->startOfDay();
            $pedido->issued_at = $emissao;

            $fornecedor = $pedido->supplier()->firstOrFail();

            $this->titulos->registrar(
                originType: self::ORIGEM,
                originId: $pedido->id,
                // Snapshot: renomear o fornecedor não reescreve o que já
                // se deve (o Financeiro guarda o nome, não a FK).
                supplierName: $fornecedor->legal_name,
                amount: $pedido->total,
                dueDate: $fornecedor->vencimentoAPartirDe($emissao),
                categoryId: null,
            );

            $this->transicionar($pedido, PurchaseOrderStatus::Confirmed, $por);

            PurchaseOrderConfirmed::dispatch($pedido);

            return $pedido;
        });
    }
}
