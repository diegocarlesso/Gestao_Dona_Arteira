<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\Catalog\Services\ProductLookupService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Exceptions\PedidoDeCompraInvalido;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Abre ou edita um pedido de compra em rascunho — BR-402,
 * docs/11-Compras (Fase 1).
 *
 * **Um Service para os dois** (e por isso `Save`, não `Create`): abrir e
 * editar um rascunho são a mesma regra — os itens entram do mesmo jeito e
 * o total se recalcula igual. É o mesmo argumento de `SaveOrderService` em
 * Vendas, e duas classes quase iguais divergiriam na primeira correção que
 * alguém fizesse só numa delas.
 *
 * **O custo vem digitado, não do catálogo.** No pedido de venda o preço é
 * do ERP e congela ao adicionar (BR-302); na compra quem manda no valor é
 * o fornecedor, e o que se registra é o que foi negociado naquele telefonema.
 *
 * **O PC nunca fica vazio.** Um rascunho sem item (ou com tudo zerado) só
 * pode ir para dois lugares: ser esquecido ou estourar lá na frente, quando
 * o Financeiro recusar um título de R$ 0,00 (BR-501) com uma mensagem que
 * não fala a língua de quem estava montando a compra.
 */
class SavePurchaseOrderService
{
    public function __construct(private readonly ProductLookupService $catalogo) {}

    /**
     * @param  array{supplier_id: int, freight?: string|null, issued_at?: string|null, expected_delivery_at?: string|null, notes?: string|null}  $dados
     * @param  list<array{product: string, qty: string, unit_cost: string}>  $itens  produtos por `public_id`
     *
     * @throws PedidoDeCompraInvalido
     */
    public function handle(?PurchaseOrder $pedido, array $dados, array $itens, ?int $createdBy = null): PurchaseOrder
    {
        if ($pedido !== null && ! $pedido->status->rascunho()) {
            throw PedidoDeCompraInvalido::naoEditavel($pedido->status->label());
        }

        if ($itens === []) {
            throw PedidoDeCompraInvalido::semItens();
        }

        return DB::transaction(function () use ($pedido, $dados, $itens, $createdBy): PurchaseOrder {
            if ($pedido === null) {
                // Totais zerados explicitamente: os defaults `0` são do
                // banco, e o model em memória não os enxerga antes de
                // recarregar — o `bcadd` do recálculo receberia null.
                $pedido = new PurchaseOrder([
                    'number' => PurchaseOrder::proximoNumero(),
                    'status' => PurchaseOrderStatus::Draft,
                    'subtotal' => '0.00',
                    'freight' => '0.00',
                    'total' => '0.00',
                    'created_by' => $createdBy,
                ]);
            }

            $pedido->supplier_id = $dados['supplier_id'];
            $pedido->freight = $this->dinheiro($dados['freight'] ?? '0');
            $pedido->issued_at = $this->data($dados['issued_at'] ?? null);
            $pedido->expected_delivery_at = $this->data($dados['expected_delivery_at'] ?? null);
            $pedido->notes = $dados['notes'] ?? null;
            $pedido->save();

            $this->sincronizarItens($pedido, $itens);
            $this->recalcular($pedido);

            return $pedido;
        });
    }

    /**
     * @param  list<array{product: string, qty: string, unit_cost: string}>  $itens
     *
     * @throws PedidoDeCompraInvalido
     */
    private function sincronizarItens(PurchaseOrder $pedido, array $itens): void
    {
        $existentes = $pedido->items()->get()->keyBy('product_id');
        $mantidos = [];

        foreach ($itens as $linha) {
            // Quem sabe quem é o produto é o Catálogo (ADR-0020): o
            // Compras tem o `public_id` que veio da tela e pede o id.
            $resumo = $this->catalogo->resumoPorPublicId($linha['product']);

            if ($resumo === null) {
                // Diferente de Vendas, que ignora item não mapeado do Woo:
                // ali a alternativa era perder a venda inteira; aqui a
                // linha veio de alguém escolhendo na tela, e sumir com ela
                // em silêncio faria o PC valer menos do que o combinado.
                throw PedidoDeCompraInvalido::produtoDesconhecido($linha['product']);
            }

            $item = $existentes->get($resumo->id)
                ?? $pedido->items()->make(['product_id' => $resumo->id]);

            $item->qty = $linha['qty'];
            $item->unit_cost = $this->dinheiro($linha['unit_cost']);
            $item->line_total = $item->calcularTotal();
            $item->save();

            $mantidos[] = $item->id;
        }

        $pedido->items()->whereNotIn('id', $mantidos)->delete();
    }

    /**
     * Subtotal pela soma das linhas; total = subtotal + frete.
     *
     * Em `bc` do começo ao fim (ADR-0013): o total daqui vira o valor do
     * título a pagar, e um centavo perdido no float seria um centavo de
     * diferença entre o que se pediu e o que se deve.
     *
     * @throws PedidoDeCompraInvalido
     */
    private function recalcular(PurchaseOrder $pedido): void
    {
        $subtotal = '0.00';

        foreach ($pedido->items()->get() as $item) {
            $subtotal = bcadd($subtotal, $item->line_total, 2);
        }

        $total = bcadd($subtotal, $pedido->freight, 2);

        if (bccomp($total, '0.00', 2) !== 1) {
            throw PedidoDeCompraInvalido::semValor();
        }

        $pedido->subtotal = $subtotal;
        $pedido->total = $total;
        $pedido->save();
    }

    /**
     * Normaliza para duas casas — os valores chegam da tela como "89,9"
     * já convertido para "89.9", ou "15" inteiro, e o banco guarda
     * DECIMAL(15,2).
     */
    private function dinheiro(?string $valor): string
    {
        return bcadd($valor === null || $valor === '' ? '0' : $valor, '0', 2);
    }

    /**
     * As datas chegam da tela como `Y-m-d` (input `type="date"`) e as
     * colunas são `date`. Converter aqui, e não deixar a string cair no
     * atributo, é o que mantém `issued_at` sendo sempre uma data no
     * domínio — o `ConfirmPurchaseOrderService` conta o vencimento a
     * partir dela, e somar dias a uma string não é operação que exista.
     */
    private function data(?string $valor): ?Carbon
    {
        return $valor === null || $valor === '' ? null : Carbon::parse($valor)->startOfDay();
    }
}
