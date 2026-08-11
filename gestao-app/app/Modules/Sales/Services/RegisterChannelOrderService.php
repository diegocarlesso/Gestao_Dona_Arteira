<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Exceptions\ReservaInvalida;
use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Exceptions\PedidoInvalido;
use App\Modules\Sales\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registra um pedido vindo de um canal externo — BR-303/BR-304/BR-703.
 *
 * Gêmeo do `SaveOrderService`, com uma diferença que justifica existir
 * separado: **o preço não vem do varejo do ERP, vem do canal**. No pedido
 * de balcão o item congela o preço de varejo de agora (BR-302); no pedido
 * do site o cliente já viu e pagou um preço — recalculá-lo pelo ERP
 * reescreveria o que a loja cobrou. O Woo é o fato de origem (BR-703): o
 * ERP importa os valores, não os inventa.
 *
 * **Orquestra a entrada inteira do lado de Vendas** e devolve primitivos
 * (`ChannelOrderResult`): cria o rascunho, e — se `$tentarReservar` — pede
 * a confirmação com reserva (a mesma `ConfirmOrderService` do balcão). Se
 * não houver disponível, o pedido fica em rascunho com a pendência anotada
 * em vez de falhar — venda do site não se perde por falta de saldo, que é
 * o normal antes da contagem física (cutover).
 *
 * Faz a orquestração aqui, e não em quem chama, porque quem chama é a
 * Integração — que não pode tocar `Order` nem `ConfirmOrderService` com o
 * model na mão (ADR-0020, fronteira verificada no CI).
 */
class RegisterChannelOrderService
{
    public function __construct(private readonly ConfirmOrderService $confirmacao) {}

    /**
     * @param  list<array{product_id: int, qty: string, unit_price: string, note?: string|null}>  $itens
     * @param  string|null  $notaInicial  pendência a anexar já na criação (ex.: itens não mapeados)
     * @param  string|null  $customerNote  o que o cliente digitou no checkout do canal (BR-707) — nunca
     *                                     confundir com `$notaInicial`, que é gerado pelo próprio ERP
     * @param  string|null  $shippingMethod  forma de entrega escolhida no canal (texto livre, BR-707)
     * @param  bool  $entregueSemBaixa  histórico já entregue no canal (Woo `completed`): grava o
     *                                  pedido direto em `Entregue`, sem reserva nem baixa no ledger —
     *                                  a peça já saiu antes de o ERP existir. Só se aplica quando
     *                                  `$tentarReservar` é `false` (é sempre o caso: `completed` nunca
     *                                  está em `RESERVAVEIS`); os dois juntos não acontecem na prática.
     * @param  Carbon|null  $orderedAt  data real do pedido no canal (BR-703/BR-707) — sem isso, o
     *                                  Eloquent carimba `created_at` com o instante da importação, e
     *                                  uma puxada histórica faria todo pedido antigo parecer vendido
     *                                  hoje no dashboard. `null` (pedido de balcão, ou canal sem essa
     *                                  data) mantém o comportamento padrão do Eloquent.
     * @param  Carbon|null  $entregueEm  data real da entrega/conclusão no canal, só usada com
     *                                   `$entregueSemBaixa`; `null` cai para `now()`.
     * @param  string|null  $paymentMethod  forma de pagamento do canal (texto livre, BR-501)
     * @param  Carbon|null  $paidAt  quando o canal confirmou o pagamento — `null` = ainda não pago
     *                               (o pedido nasce com título a receber aberto; preenchido, nasce
     *                               já baixado). Não é o mesmo dado que `$orderedAt`: um pedido pode
     *                               ter sido feito num dia e pago (ou nunca pago) noutro.
     */
    public function handle(
        OrderChannel $channel,
        ?int $customerId,
        ?string $channelOrderRef,
        array $itens,
        string $shipping = '0.00',
        string $discount = '0.00',
        ?string $channelTotal = null,
        bool $tentarReservar = false,
        ?string $notaInicial = null,
        ?string $customerNote = null,
        ?string $shippingMethod = null,
        bool $entregueSemBaixa = false,
        ?Carbon $orderedAt = null,
        ?Carbon $entregueEm = null,
        ?string $paymentMethod = null,
        ?Carbon $paidAt = null,
    ): ChannelOrderResult {
        if ($itens === []) {
            throw PedidoInvalido::semItens();
        }

        return DB::transaction(function () use ($channel, $customerId, $channelOrderRef, $itens, $shipping, $discount, $channelTotal, $tentarReservar, $notaInicial, $customerNote, $shippingMethod, $entregueSemBaixa, $orderedAt, $entregueEm, $paymentMethod, $paidAt): ChannelOrderResult {
            $pedido = new Order([
                'number' => $this->proximoNumero(),
                'channel' => $channel,
                'channel_order_ref' => $channelOrderRef,
                'customer_id' => $customerId,
                'status' => OrderStatus::Draft,
                'price_list' => 'retail',
                'subtotal' => '0.00',
                'discount' => $this->dinheiro($discount),
                'shipping' => $this->dinheiro($shipping),
                'total' => '0.00',
                'notes' => $notaInicial,
                'customer_note' => $customerNote,
                'shipping_method' => $shippingMethod,
                'payment_method' => $paymentMethod,
                'paid_at' => $paidAt,
            ]);

            if ($orderedAt !== null) {
                // Atribuído antes do save(): o Eloquent só carimba
                // `created_at` sozinho quando o atributo ainda não está
                // "sujo" — setar aqui é o que faz o pedido nascer com a
                // data real do canal em vez do instante da importação.
                $pedido->created_at = $orderedAt;
            }

            $pedido->save();

            foreach ($itens as $linha) {
                $pedido->items()->create([
                    'product_id' => $linha['product_id'],
                    'qty' => $linha['qty'],
                    'unit_price' => $this->dinheiro($linha['unit_price']),
                    'discount' => '0.00',
                    'note' => $linha['note'] ?? null,
                ]);
            }

            $this->recalcular($pedido, $channelTotal);

            if (! $tentarReservar) {
                if ($entregueSemBaixa) {
                    // Direto para Entregue, sem passar pelo ConfirmOrderService:
                    // não há reserva a fazer (a peça já saiu no site) nem baixa
                    // de ledger a registrar (histórico, não fulfillment real).
                    $pedido->status = OrderStatus::Entregue;
                    $pedido->delivered_at = $entregueEm ?? now();
                    $pedido->save();
                }

                return new ChannelOrderResult($pedido->id, false);
            }

            try {
                $this->confirmacao->handle($pedido);

                return new ChannelOrderResult($pedido->id, true);
            } catch (ReservaInvalida $e) {
                // Sem saldo — o savepoint da confirmação já reverteu a
                // reserva parcial; recarrego para não gravar sobre o status
                // confirmado que ficou só na memória.
                $pedido->refresh();
                $this->anotar($pedido, 'Sem disponível para reservar: '.$e->getMessage());

                return new ChannelOrderResult($pedido->id, false);
            }
        });
    }

    /**
     * Subtotal pela soma dos itens; total = subtotal + frete − desconto.
     *
     * Confere contra o total que o canal informou: divergência de centavos
     * (cupom de carrinho, arredondamento de plugin) não derruba a
     * importação — anota na observação para alguém conferir. Perder a venda
     * por um centavo seria pior que registrá-la com um aviso.
     */
    private function recalcular(Order $pedido, ?string $channelTotal): void
    {
        $subtotal = '0.00';

        foreach ($pedido->items()->get() as $item) {
            $subtotal = bcadd($subtotal, $item->lineTotal(), 2);
        }

        $total = bcsub(bcadd($subtotal, $pedido->shipping, 2), $pedido->discount, 2);

        $pedido->subtotal = $subtotal;
        $pedido->total = $total;

        if ($channelTotal !== null && bccomp($this->dinheiro($channelTotal), $total, 2) !== 0) {
            $this->anotar($pedido, "⚠ Total do site (R$ {$channelTotal}) difere do calculado (R$ {$total}) — conferir.");
        }

        $pedido->save();
    }

    private function anotar(Order $pedido, string $aviso): void
    {
        $pedido->notes = $pedido->notes === null ? $aviso : $pedido->notes."\n".$aviso;
        $pedido->save();
    }

    /**
     * Normaliza para duas casas — os valores do Woo chegam como "89.9" ou
     * "15", e o `bccomp` de conferência compara string a string.
     */
    private function dinheiro(string $valor): string
    {
        return bcadd($valor === '' ? '0' : $valor, '0', 2);
    }

    /**
     * O próximo número sequencial — mesma regra do `SaveOrderService`: a
     * corrida é improvável no volume da loja, e o índice único em `number`
     * arbitra se acontecer.
     */
    private function proximoNumero(): int
    {
        return ((int) Order::query()->max('number')) + 1;
    }
}
