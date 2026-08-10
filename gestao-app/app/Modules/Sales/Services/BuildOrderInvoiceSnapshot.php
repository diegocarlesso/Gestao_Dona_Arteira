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
        $pedido = Order::query()->with(['items', 'customer'])->find($orderId);

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
            shippingAddress: $this->enderecoDe($cliente),
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
     * O endereço de entrega do cliente — o padrão, ou o primeiro cadastrado.
     *
     * Cair no primeiro em vez de devolver nulo é deliberado: cliente com um
     * endereço só normalmente não o marcou como padrão, e recusar a nota
     * por causa de um checkbox seria travar a operação por burocracia de
     * cadastro. Cliente sem endereço nenhum devolve nulo, e aí a pendência
     * é real (a mesma que `Customer::pendencias()` já sinaliza na tela).
     */
    private function enderecoDe(?Customer $cliente): ?OrderInvoiceAddress
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

        return new OrderInvoiceAddress(
            zip: $endereco->zip,
            street: $endereco->street,
            number: $endereco->number,
            complement: $endereco->complement,
            district: $endereco->district,
            city: $endereco->city,
            // Ainda não há de onde tirar: `customer_addresses` não guarda o
            // código IBGE do município. A NF-e exige (`enderDest/cMun`), e
            // quem trata a falta é o Fiscal — aqui só se declara o fato.
            cityCode: null,
            state: $endereco->state,
        );
    }
}
