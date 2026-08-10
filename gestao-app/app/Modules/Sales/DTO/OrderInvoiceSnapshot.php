<?php

declare(strict_types=1);

namespace App\Modules\Sales\DTO;

/**
 * Tudo o que é preciso saber de um pedido para faturá-lo — ADR-0025 §2.
 *
 * A resposta de Vendas à pergunta que o Fiscal não pode fazer olhando o
 * model: `Fiscal` nunca toca `Sales\Models\Order` (ADR-0020, verificado no
 * CI), então Vendas publica um retrato em primitivos e o Fiscal monta a
 * nota a partir dele. Mesmo espírito do `Catalog\DTO\ProductSnapshot`.
 *
 * É um **retrato**, não uma referência: os valores aqui são os de quando o
 * snapshot foi tirado. Se o pedido mudar depois, a nota já emitida não muda
 * junto — imutabilidade da NF-e autorizada (docs/14 §3), correção só por
 * evento.
 *
 * Campos de cliente nuláveis porque o pedido de balcão não exige cadastro
 * (a exigência de documento é do faturamento, não do pedido — mesma lógica
 * da BR-001). A ausência é um fato a ser tratado pelo Fiscal na
 * pré-validação, não um erro deste objeto.
 */
final readonly class OrderInvoiceSnapshot
{
    /**
     * @param  list<OrderInvoiceItem>  $items
     */
    public function __construct(
        public int $orderId,
        public string $orderPublicId,
        public int $orderNumber,
        public string $channel,
        public ?int $customerId,
        public ?string $customerName,
        /** `individual` ou `company` — vocabulário de Vendas; a tradução
         * para PF/PJ do perfil fiscal é do módulo Fiscal. */
        public ?string $customerType,
        public ?string $customerDocument,
        /** Inscrição estadual: é ela que diz se o PJ é contribuinte de
         * ICMS (`indIEDest` no XML). Nula para PF e para PJ isento. */
        public ?string $customerStateRegistration,
        public ?string $customerEmail,
        public ?OrderInvoiceAddress $shippingAddress,
        public array $items,
        public string $subtotal,
        public string $discount,
        public string $shipping,
        public string $total,
    ) {}
}
