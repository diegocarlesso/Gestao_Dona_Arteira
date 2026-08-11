<?php

declare(strict_types=1);

namespace App\Modules\Sales\DTO;

/**
 * O cartão de visita de um pedido — para quem precisa mostrar "pedido
 * #123, de Fulana" sem importar `Sales\Models\Order` (ADR-0020). Mesmo
 * papel do `ProductSummary` no Catálogo.
 */
final class OrderSummary
{
    public function __construct(
        public readonly string $publicId,
        public readonly int $number,
        public readonly string $channelLabel,
        public readonly string $statusLabel,
        public readonly ?string $customerName,
    ) {}
}
