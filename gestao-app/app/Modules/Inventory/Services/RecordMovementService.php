<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Events\StockMovementRecorded;
use App\Modules\Inventory\Exceptions\MovimentoInvalido;
use App\Modules\Inventory\Exceptions\SaldoInsuficiente;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;

/**
 * A única via de escrita do estoque — ADR-0008, docs/09-Estoque §3.
 *
 * **Nenhum código altera `qty_on_hand` diretamente.** Toda mudança nasce
 * como movimento imutável, e o saldo é atualizado na mesma transação. É
 * essa disciplina que torna o saldo reconciliável: se a soma dos
 * movimentos deixar de bater com o saldo, houve bug ou intervenção manual
 * no banco — e não uma terceira via legítima de escrita que ninguém
 * lembrava.
 *
 * O serviço é o dono da transação (ADR-0015). Quem o chama já pode estar
 * numa transação maior — expedir um pedido move várias linhas —, e o
 * `DB::transaction` aninhado do Laravel vira savepoint, que é o
 * comportamento desejado: ou o pedido inteiro move, ou nada move.
 */
class RecordMovementService
{
    /**
     * Registra um movimento e atualiza o saldo.
     *
     * @param  string  $qty  quantidade **sempre positiva**; o sinal vem do tipo
     * @param  string|null  $unitCost  custo unitário, só nas entradas que o têm (BR-206)
     *
     * @throws MovimentoInvalido
     * @throws SaldoInsuficiente
     */
    public function handle(
        int $productId,
        int $locationId,
        MovementType $type,
        string $qty,
        ?string $unitCost = null,
        ?string $notes = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $createdBy = null,
        ?\DateTimeInterface $occurredAt = null,
    ): InventoryMovement {
        if (bccomp($qty, '0', 3) !== 1) {
            throw MovimentoInvalido::quantidadeNaoPositiva($qty);
        }

        if ($type->exigeMotivo() && ($notes === null || trim($notes) === '')) {
            throw MovimentoInvalido::motivoObrigatorio($type->value);
        }

        return DB::transaction(function () use (
            $productId, $locationId, $type, $qty, $unitCost, $notes, $referenceType, $referenceId, $createdBy, $occurredAt
        ): InventoryMovement {
            $saldo = $this->saldoTravado($productId, $locationId);

            // Por `bcmul` com o sinal do tipo, e não concatenando "-": a
            // multiplicação também normaliza a escala, então o movimento
            // devolvido traz `10.000` como o banco guardou, e não o `10`
            // que o chamador digitou. Quem usa o retorno na sequência
            // compara com o que está gravado, não com o que passou.
            $assinada = bcmul($qty, (string) $type->sinal(), 3);
            $novoSaldo = bcadd($saldo->qty_on_hand, $assinada, 3);

            // BR-201, e vale para todo tipo — inclusive ajuste. Ver o
            // porquê em `SaldoInsuficiente`.
            if (bccomp($novoSaldo, '0', 3) === -1) {
                throw SaldoInsuficiente::para($saldo->qty_on_hand, $qty, $productId);
            }

            $movimento = InventoryMovement::query()->create([
                'product_id' => $productId,
                'location_id' => $locationId,
                'type' => $type,
                'qty' => $assinada,
                'unit_cost' => $unitCost,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'notes' => $notes,
                'occurred_at' => $occurredAt ?? now(),
                'created_by' => $createdBy,
            ]);

            $saldo->avg_cost = $this->novoCustoMedio($saldo, $type, $qty, $unitCost);
            $saldo->qty_on_hand = $novoSaldo;
            $saldo->save();

            // Evento, e não chamada direta: quem reage a movimento —
            // publicar estoque no Woo (BR-204), alertar mínimo — não é
            // problema deste serviço saber (ADR-0020).
            StockMovementRecorded::dispatch($movimento);

            return $movimento;
        });
    }

    /**
     * O saldo do par produto/local, travado para escrita.
     *
     * `lockForUpdate` depois de `firstOrCreate` e não antes: a linha pode
     * não existir na primeira movimentação do produto, e travar o que não
     * existe não impede outra transação de criá-la. Criar primeiro faz o
     * índice único `uq_inventory_balances_produto_local` arbitrar quem
     * ganha, e o lock vale a partir daí.
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

    /**
     * Custo médio móvel — BR-206.
     *
     * Só entrada **com custo** recalcula. Saída consome o custo corrente e
     * não o altera; ajuste de contagem também não, e é de propósito:
     * contagem corrige quantidade, não valor — deixá-la mexer no custo
     * médio faria uma peça reaparecida na prateleira valer zero e
     * contaminar a margem de todas as outras.
     */
    private function novoCustoMedio(
        InventoryBalance $saldo,
        MovementType $type,
        string $qty,
        ?string $unitCost,
    ): string {
        if (! $type->entrada() || $type->ehAjuste() || $unitCost === null) {
            return $saldo->avg_cost;
        }

        $quantidadeFinal = bcadd($saldo->qty_on_hand, $qty, 3);

        if (bccomp($quantidadeFinal, '0', 3) !== 1) {
            return $unitCost;
        }

        $valorAtual = bcmul($saldo->qty_on_hand, $saldo->avg_cost, 6);
        $valorEntrando = bcmul($qty, $unitCost, 6);

        return bcdiv(bcadd($valorAtual, $valorEntrando, 6), $quantidadeFinal, 2);
    }
}
