<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Email\Listeners;

use App\Modules\Catalog\Services\ProductLookupService;
use App\Modules\Integrations\Email\Notifications\PedidoConfirmadoNotification;
use App\Modules\Sales\Events\OrderConfirmed;
use Brick\Money\Money;
use Illuminate\Support\Facades\Notification;

/**
 * Ouve a confirmação do pedido e dispara o e-mail transacional — docs/15,
 * BR-310.
 *
 * A camada anticorrupção reagindo a um evento de domínio (ADR-0020):
 * Vendas anuncia `OrderConfirmed` sem saber que existe e-mail; a
 * Integração ouve. Enxerga só o **Event** de Vendas e o **Service**
 * público do Catálogo — nunca `Order`, `Customer` ou `Product` (a
 * fronteira é verificada no CI).
 *
 * Cliente sem e-mail (ou pedido de balcão sem cliente) não é falha: é
 * silêncio. A maioria dos pedidos hoje é de balcão, e um pedido sem
 * destinatário não é um envio que falhou — é um envio que não existe.
 */
class EnviarEmailPedidoConfirmado
{
    public function __construct(private readonly ProductLookupService $produtos) {}

    public function handle(OrderConfirmed $event): void
    {
        $pedido = $event->pedido;
        $email = $pedido->customer?->email;

        if ($email === null) {
            return;
        }

        $itens = $pedido->items;
        $resumos = $this->produtos->resumos($itens->pluck('product_id')->all());

        $linhas = $itens->map(function ($item) use ($resumos): array {
            $produto = $resumos[$item->product_id] ?? null;

            return [
                'nome' => $produto?->name ?? "Produto #{$item->product_id}",
                'qtd' => self::formatarQuantidade($item->qty),
                'preco' => Money::of($item->unit_price, 'BRL')->formatTo('pt_BR'),
            ];
        })->all();

        Notification::route('mail', $email)->notify(new PedidoConfirmadoNotification(
            numeroPedido: (string) $pedido->number,
            nomeCliente: $pedido->customer->name,
            itens: $linhas,
            total: Money::of($pedido->total, 'BRL')->formatTo('pt_BR'),
        ));
    }

    /**
     * Quantidade é `DECIMAL(15,3)` para o ledger (regra 6 do projeto); no
     * e-mail ninguém quer ler "2.000x" — só o texto muda, o dado guardado
     * continua com as três casas.
     */
    private static function formatarQuantidade(string $qty): string
    {
        return rtrim(rtrim($qty, '0'), '.') ?: '0';
    }
}
