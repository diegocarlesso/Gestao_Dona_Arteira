<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Catalog\Services\ProductLookupService;
use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Exceptions\PedidoInvalido;
use App\Modules\Sales\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Cria ou edita um pedido em rascunho — BR-302/BR-303.
 *
 * Um Service para os dois porque a diferença entre abrir e editar um
 * rascunho é nenhuma do ponto de vista das regras: os itens congelam
 * preço do mesmo jeito e o total se recalcula igual.
 *
 * **Preço congela quando o item entra**, não a cada edição: um item já no
 * pedido guarda o preço de quando foi adicionado (BR-302); trocar a
 * quantidade não o repreça. Quem quiser o preço novo remove e adiciona de
 * novo — aí é um item novo, com o preço de agora.
 */
class SaveOrderService
{
    public function __construct(private readonly ProductLookupService $catalogo) {}

    /**
     * @param  array{customer_id?: int|null, notes?: string|null, payment_method?: string|null, pago_agora?: bool}  $dados
     * @param  list<array{product: string, qty: string, note?: string|null}>  $itens  produtos por `public_id`
     *
     * @throws PedidoInvalido
     */
    public function handle(?Order $pedido, array $dados, array $itens, ?int $createdBy = null): Order
    {
        if ($pedido !== null && ! $pedido->status->rascunho()) {
            throw PedidoInvalido::naoEditavel($pedido->status->label());
        }

        return DB::transaction(function () use ($pedido, $dados, $itens, $createdBy): Order {
            if ($pedido === null) {
                // Totais zerados explicitamente: os defaults `0` são do
                // banco, e o model em memória não os enxerga antes de
                // recarregar — o `bcadd` do recálculo receberia null.
                $pedido = new Order([
                    'number' => $this->proximoNumero(),
                    'channel' => OrderChannel::Erp,
                    'status' => OrderStatus::Draft,
                    'price_list' => 'retail',
                    'subtotal' => '0',
                    'discount' => '0',
                    'shipping' => '0',
                    'total' => '0',
                    'created_by' => $createdBy,
                ]);
            }

            $pedido->customer_id = $dados['customer_id'] ?? null;
            $pedido->notes = $dados['notes'] ?? null;
            $pedido->payment_method = $dados['payment_method'] ?? null;
            // Preserva o instante original se o rascunho já estava marcado
            // como pago — reeditar o pedido não deveria mudar quando o
            // dinheiro entrou.
            $pedido->paid_at = ($dados['pago_agora'] ?? false) ? ($pedido->paid_at ?? now()) : null;
            $pedido->save();

            $this->sincronizarItens($pedido, $itens);
            $this->recalcularTotais($pedido);

            return $pedido;
        });
    }

    /**
     * @param  list<array{product: string, qty: string, note?: string|null}>  $itens
     *
     * @throws PedidoInvalido
     */
    private function sincronizarItens(Order $pedido, array $itens): void
    {
        $existentes = $pedido->items()->get()->keyBy('product_id');
        $mantidos = [];

        foreach ($itens as $linha) {
            $resumo = $this->catalogo->resumoPorPublicId($linha['product']);

            if ($resumo === null) {
                continue;
            }

            $item = $existentes->get($resumo->id);

            if ($item === null) {
                // Item novo: congela o preço de varejo de agora.
                $preco = $this->catalogo->snapshot($resumo->id)?->retailPrice;

                if ($preco === null) {
                    throw PedidoInvalido::produtoSemPreco($resumo->sku);
                }

                $item = $pedido->items()->make(['product_id' => $resumo->id, 'unit_price' => $preco]);
            }

            $item->qty = $linha['qty'];
            $item->note = $linha['note'] ?? null;
            $item->save();

            $mantidos[] = $item->id;
        }

        $pedido->items()->whereNotIn('id', $mantidos)->delete();
    }

    private function recalcularTotais(Order $pedido): void
    {
        $subtotal = '0.00';

        foreach ($pedido->items()->get() as $item) {
            $subtotal = bcadd($subtotal, $item->lineTotal(), 2);
        }

        // Desconto e frete zerados neste corte (pasta 10 §2.1).
        $pedido->subtotal = $subtotal;
        $pedido->total = bcsub(bcadd($subtotal, $pedido->shipping, 2), $pedido->discount, 2);
        $pedido->save();
    }

    /**
     * O próximo número sequencial. Corrida improvável no volume da loja, e
     * o índice único em `number` arbitra se acontecer.
     */
    private function proximoNumero(): int
    {
        return ((int) Order::query()->max('number')) + 1;
    }
}
