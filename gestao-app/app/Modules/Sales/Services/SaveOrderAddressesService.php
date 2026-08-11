<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\Order;

/**
 * Grava o endereço de cobrança e/ou entrega de um pedido — BR-707.
 *
 * Diferente do `ImportCustomerService` (que casa endereço de cliente por
 * conteúdo, porque o mesmo cliente pode ter vários do mesmo tipo): aqui a
 * chave é só `(order_id, type)` — a UNIQUE do banco já garante no máximo
 * um `billing` e um `shipping` por pedido — então `updateOrCreate` direto
 * resolve, sem precisar de "endereço equivalente".
 *
 * Recebe **id**, não model — mesmo motivo do `BuildOrderInvoiceSnapshot`
 * (ADR-0020 §2): quem chama pode ser o Integrations/WooCommerce, e ele não
 * enxerga `Sales\Models\Order`. Buscar o pedido é problema deste módulo.
 */
class SaveOrderAddressesService
{
    public function __construct(private readonly ResolveIbgeCityCode $municipios) {}

    /**
     * @param  list<array{type: string, zip: string, street: string, number: ?string, complement: ?string, district: ?string, city: string, state: string}>  $enderecos
     */
    public function handle(int $orderId, array $enderecos): void
    {
        if ($enderecos === []) {
            return;
        }

        $pedido = Order::query()->findOrFail($orderId);

        foreach ($enderecos as $dados) {
            $pedido->addresses()->updateOrCreate(
                [
                    'order_id' => $pedido->id,
                    'type' => $dados['type'],
                ],
                [
                    'zip' => $dados['zip'],
                    'street' => $dados['street'],
                    'number' => $dados['number'] ?? null,
                    'complement' => $dados['complement'] ?? null,
                    'district' => $dados['district'] ?? null,
                    'city' => $dados['city'],
                    'state' => $dados['state'],
                    'city_code' => $this->municipios->resolver($dados['city'], $dados['state']),
                ]
            );
        }
    }
}
