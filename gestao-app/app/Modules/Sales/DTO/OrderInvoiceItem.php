<?php

declare(strict_types=1);

namespace App\Modules\Sales\DTO;

/**
 * Uma linha do pedido, pronta para virar linha de nota fiscal.
 *
 * Junta o que é de Vendas (quantidade, preço congelado, desconto) com o que
 * é do Catálogo (SKU, nome, NCM, origem) porque **Vendas já conversa com o
 * Catálogo** e o Fiscal não deveria precisar conversar com os dois para
 * montar uma nota. A alternativa — o Fiscal pedir a Vendas os itens e ao
 * Catálogo os NCMs — obrigaria o Fiscal a saber que `product_id` é chave do
 * Catálogo, que é exatamente o acoplamento que o ADR-0020 evita.
 *
 * Campos fiscais nuláveis: o catálogo migrado do Woo nasce sem NCM (pasta
 * 32). Quem decide se isso impede a emissão é o Fiscal (BR-606), não aqui.
 */
final readonly class OrderInvoiceItem
{
    public function __construct(
        public int $productId,
        public ?string $sku,
        public ?string $name,
        public ?string $unit,
        public string $qty,
        public string $unitPrice,
        public string $discount,
        public string $lineTotal,
        public ?string $ncm,
        public ?string $cest,
        public ?string $origin,
        public ?string $gtin,
    ) {}
}
