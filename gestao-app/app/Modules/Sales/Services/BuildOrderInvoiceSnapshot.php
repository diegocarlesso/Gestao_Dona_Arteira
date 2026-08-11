<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Catalog\DTO\ProductFiscalData;
use App\Modules\Catalog\Services\ProductLookupService;
use App\Modules\Sales\DTO\OrderInvoiceAddress;
use App\Modules\Sales\DTO\OrderInvoiceItem;
use App\Modules\Sales\DTO\OrderInvoiceSnapshot;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderAddress;
use App\Modules\Sales\Models\OrderItem;

/**
 * A porta pela qual o Fiscal pergunta a Vendas o que faturar — ADR-0025 §2.
 *
 * Recebe **id**, não model, de propósito. Quem chama é o job de emissão do
 * módulo Fiscal, e se ele precisasse do `Order` na mão para passar adiante
 * teria de importá-lo — furando a fronteira do ADR-0020 exatamente no ponto
 * que este Service existe para proteger. Buscar o pedido é problema de
 * Vendas; o Fiscal só tem o número da porta.
 *
 * Devolve `null` quando o pedido não existe (apagado entre a confirmação e
 * o processamento da fila, por exemplo) em vez de estourar: para quem
 * chama, "não há o que faturar" é resposta, não falha.
 */
class BuildOrderInvoiceSnapshot
{
    public function __construct(private readonly ProductLookupService $catalogo) {}

    public function handle(int $orderId): ?OrderInvoiceSnapshot
    {
        $pedido = Order::query()->with(['items', 'customer', 'addresses'])->find($orderId);

        if ($pedido === null) {
            return null;
        }

        /** @var list<OrderItem> $itens */
        $itens = $pedido->items->all();

        // Uma consulta só ao Catálogo para a nota inteira (BR-606): NCM,
        // origem e SKU de todas as linhas de uma vez.
        $fiscais = $this->catalogo->dadosFiscais(
            array_map(static fn (OrderItem $i): int => $i->product_id, $itens)
        );

        $cliente = $pedido->customer;

        return new OrderInvoiceSnapshot(
            orderId: $pedido->id,
            orderPublicId: $pedido->public_id,
            orderNumber: $pedido->number,
            channel: $pedido->channel->value,
            customerId: $cliente?->id,
            customerName: $cliente?->name,
            customerType: $cliente?->type->value,
            // O documento sem máscara: o XML da NF-e recusa pontuação, e
            // formatar para depois limpar do outro lado da fronteira seria
            // trabalho para desfazer trabalho.
            customerDocument: $cliente?->doc,
            customerStateRegistration: $cliente?->state_registration,
            customerEmail: $cliente?->email,
            shippingAddress: $this->enderecoDe($pedido, $cliente),
            items: array_map(
                fn (OrderItem $item): OrderInvoiceItem => $this->linha($item, $fiscais[$item->product_id] ?? null),
                $itens
            ),
            subtotal: $pedido->subtotal,
            discount: $pedido->discount,
            shipping: $pedido->shipping,
            total: $pedido->total,
        );
    }

    private function linha(OrderItem $item, ?ProductFiscalData $fiscal): OrderInvoiceItem
    {
        return new OrderInvoiceItem(
            productId: $item->product_id,
            sku: $fiscal?->sku,
            name: $fiscal?->name,
            unit: $fiscal?->unit,
            qty: $item->qty,
            unitPrice: $item->unit_price,
            discount: $item->discount,
            lineTotal: $item->lineTotal(),
            ncm: $fiscal?->ncm,
            cest: $fiscal?->cest,
            origin: $fiscal?->origin,
            gtin: $fiscal?->gtin,
        );
    }

    /**
     * O endereço de entrega deste pedido — BR-707.
     *
     * O `OrderAddress` do próprio pedido vence: a entrega às vezes não é no
     * endereço do próprio comprador (presente para outra pessoa), e o
     * endereço padrão do cliente sairia errado nesse caso. Dentro do
     * pedido, `shipping` vence `billing` — mesma prioridade que o endereço
     * do cliente já tinha entre "padrão de entrega" e "qualquer outro".
     *
     * Só cai para o endereço padrão do **cliente** (comportamento anterior
     * a esta mudança) quando o pedido não tiver `OrderAddress` nenhum —
     * pedido de balcão, ou pedido antigo importado antes do BR-707.
     */
    private function enderecoDe(Order $pedido, ?Customer $cliente): ?OrderInvoiceAddress
    {
        $enderecosDoPedido = $pedido->addresses;

        $doPedido = $enderecosDoPedido->firstWhere('type', 'shipping')
            ?? $enderecosDoPedido->first();

        if ($doPedido instanceof OrderAddress) {
            return $this->paraSnapshot($doPedido);
        }

        return $this->enderecoDoCliente($cliente);
    }

    /**
     * O endereço de entrega do cliente — o padrão, ou o primeiro cadastrado.
     *
     * Cair no primeiro em vez de devolver nulo é deliberado: cliente com um
     * endereço só normalmente não o marcou como padrão, e recusar a nota
     * por causa de um checkbox seria travar a operação por burocracia de
     * cadastro. Cliente sem endereço nenhum devolve nulo, e aí a pendência
     * é real (a mesma que `Customer::pendencias()` já sinaliza na tela).
     */
    private function enderecoDoCliente(?Customer $cliente): ?OrderInvoiceAddress
    {
        if ($cliente === null) {
            return null;
        }

        $endereco = $cliente->addresses()
            ->orderByDesc('is_default_shipping')
            ->orderBy('id')
            ->first();

        if (! $endereco instanceof CustomerAddress) {
            return null;
        }

        return $this->paraSnapshot($endereco);
    }

    private function paraSnapshot(OrderAddress|CustomerAddress $endereco): OrderInvoiceAddress
    {
        return new OrderInvoiceAddress(
            zip: $endereco->zip,
            street: $endereco->street,
            number: $endereco->number,
            complement: $endereco->complement,
            district: $endereco->district,
            city: $endereco->city,
            // `enderDest/cMun` (ADR-0026). Continua podendo ser nulo — é o
            // endereço que ainda não teve o município resolvido, e aí quem
            // recusa a emissão é o Fiscal, com mensagem própria. O que
            // mudou é que agora existe de onde tirar quando está resolvido.
            cityCode: $endereco->city_code,
            state: $endereco->state,
        );
    }
}
