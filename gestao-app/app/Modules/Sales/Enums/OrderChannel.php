<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

/**
 * Canal de origem do pedido — pasta 04, BR-303/BR-304.
 *
 * `erp` é o pedido criado aqui (balcão, atacado, encomenda); `woocommerce`
 * é o que entra do site pela integração (BR-304, corte posterior). A
 * máquina de estados é a mesma para os dois — o canal muda quem pode
 * editar (pedido Woo não se edita no ERP, só avança fulfillment).
 */
enum OrderChannel: string
{
    case Erp = 'erp';
    case WooCommerce = 'woocommerce';

    public function label(): string
    {
        return match ($this) {
            self::Erp => 'ERP',
            self::WooCommerce => 'Site',
        };
    }
}
