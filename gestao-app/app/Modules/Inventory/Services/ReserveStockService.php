<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Exceptions\ReservaInvalida;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockReservation;
use Illuminate\Support\Facades\DB;

/**
 * Reserva, libera e consome estoque — BR-203, docs/09-Estoque §4.
 *
 * A superfície que Vendas chama: confirmar pedido **reserva**, cancelar
 * **libera**, expedir **consome**. Nenhuma dessas toca `qty_reserved`
 * direto — passam por aqui, com lock no saldo, do mesmo jeito que
 * `RecordMovementService` é a via única do `on_hand`. É o que mantém o
 * reservado reconciliável com a soma das reservas ativas.
 *
 * **Reserva não é movimento.** Ela não gera linha no ledger: a peça não
 * saiu, só está prometida. Quem a `on_hand` só muda no `consume` (a
 * expedição), aí sim com `sale_shipment` no ledger.
 */
class ReserveStockService
{
    public function __construct(private readonly RecordMovementService $movimentos) {}

    /**
     * Reserva `qty` do produto para uma origem (o pedido).
     *
     * `locationId` é opcional: sem ele, cai no local padrão. Vendas chama
     * sem local — não é papel dela conhecer `Location` (ADR-0020), e
     * enquanto há um ateliê só, escolher local a cada reserva seria
     * perguntar o óbvio.
     *
     * @throws ReservaInvalida
     */
    public function reservar(
        int $productId,
        string $qty,
        ?int $locationId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $createdBy = null,
    ): StockReservation {
        $locationId ??= $this->localPadrao();

        return DB::transaction(function () use ($productId, $locationId, $qty, $referenceType, $referenceId, $createdBy): StockReservation {
            $saldo = $this->saldoTravado($productId, $locationId);

            $disponivel = bcsub($saldo->qty_on_hand, $saldo->qty_reserved, 3);

            // BR-204: reserva-se o disponível, não o físico. Reservar além
            // é oversell — duas vendas na mesma peça.
            if (bccomp($qty, $disponivel, 3) === 1) {
                throw ReservaInvalida::disponivelInsuficiente($disponivel, $qty, $productId);
            }

            $reserva = StockReservation::query()->create([
                'product_id' => $productId,
                'location_id' => $locationId,
                'qty' => $qty,
                'status' => ReservationStatus::Active,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'created_by' => $createdBy,
            ]);

            $saldo->qty_reserved = bcadd($saldo->qty_reserved, $qty, 3);
            $saldo->save();

            return $reserva;
        });
    }

    /**
     * Libera a reserva — pedido cancelado. Devolve a quantidade ao
     * disponível; a peça nunca saiu, então o `on_hand` não muda.
     *
     * @throws ReservaInvalida
     */
    public function liberar(StockReservation $reserva): void
    {
        $this->encerrar($reserva, ReservationStatus::Released);
    }

    /**
     * Libera todas as reservas ativas de uma origem (o pedido cancelado).
     *
     * Existe para Vendas não precisar consultar `StockReservation` — o
     * ADR-0020 mantém o model do Estoque dentro do Estoque; Vendas diz "o
     * pedido 42 caiu, solte o que ele segurava" e o Estoque cuida do resto.
     *
     * @return int quantas reservas foram liberadas
     */
    public function liberarPorReferencia(string $referenceType, int $referenceId): int
    {
        $reservas = StockReservation::query()
            ->ativas()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->get();

        foreach ($reservas as $reserva) {
            $this->liberar($reserva);
        }

        return $reservas->count();
    }

    /**
     * Consome a reserva — pedido expedido. A peça sai de verdade:
     * `sale_shipment` no ledger (baixa `on_hand`) e a reserva deixa de
     * pesar em `qty_reserved`. O disponível não muda no ato — já estava
     * descontado desde a reserva —, e é isso que o torna correto.
     *
     * @throws ReservaInvalida
     */
    public function consumir(StockReservation $reserva, ?int $createdBy = null): void
    {
        DB::transaction(function () use ($reserva, $createdBy): void {
            $this->encerrar($reserva, ReservationStatus::Consumed);

            $this->movimentos->handle(
                productId: $reserva->product_id,
                locationId: $reserva->location_id,
                type: MovementType::SaleShipment,
                qty: $reserva->qty,
                referenceType: $reserva->reference_type,
                referenceId: $reserva->reference_id,
                createdBy: $createdBy,
            );
        });
    }

    /**
     * Baixa a reserva de `qty_reserved` e marca o novo status. Comum a
     * liberar e consumir; a diferença (mexer ou não no `on_hand`) fica em
     * quem chama.
     *
     * @throws ReservaInvalida
     */
    private function encerrar(StockReservation $reserva, ReservationStatus $novoStatus): void
    {
        DB::transaction(function () use ($reserva, $novoStatus): void {
            if (! $reserva->status->ativa()) {
                throw ReservaInvalida::jaEncerrada($reserva->status->label());
            }

            $saldo = $this->saldoTravado($reserva->product_id, $reserva->location_id);

            $saldo->qty_reserved = bcsub($saldo->qty_reserved, $reserva->qty, 3);
            $saldo->save();

            $reserva->forceFill(['status' => $novoStatus])->save();
        });
    }

    /**
     * @throws ReservaInvalida
     */
    private function localPadrao(): int
    {
        $local = Location::padrao();

        if ($local === null) {
            throw ReservaInvalida::semLocalPadrao();
        }

        return $local->id;
    }

    /**
     * O saldo do par produto/local, travado para escrita — mesmo cuidado
     * do `RecordMovementService`: cria a linha se faltar (`firstOrCreate`)
     * antes de travar, senão travar o inexistente não impede uma corrida.
     */
    private function saldoTravado(int $productId, int $locationId): InventoryBalance
    {
        InventoryBalance::query()->firstOrCreate(
            ['product_id' => $productId, 'location_id' => $locationId],
            ['qty_on_hand' => '0', 'qty_reserved' => '0', 'avg_cost' => '0'],
        );

        return InventoryBalance::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
