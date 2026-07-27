<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\StockCountStatus;
use App\Modules\Inventory\Exceptions\ContagemInvalida;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountItem;
use Illuminate\Support\Facades\DB;

/**
 * Aprova a contagem e transforma cada divergência em movimento — BR-205.
 *
 * A aprovação é onde a contagem deixa de ser papel e vira estoque. Dois
 * cuidados que a regra exige e um que os dados exigiram:
 *
 * - **Aprovador ≠ contador.** É a razão de a contagem existir como
 *   entidade em vez de formulário de ajuste em lote.
 * - **O ajuste sai contra o saldo congelado**, não contra o saldo de
 *   agora: o que a contagem afirma é o que havia no instante em que ela
 *   abriu. Movimento ocorrido durante a contagem é real e permanece.
 * - **Item não contado não vira ajuste.** `null` não é zero — numa
 *   contagem de 754 peças, tratar o não-contado como zero zeraria o
 *   estoque de tudo que a equipe não chegou a percorrer.
 */
class ApproveStockCountService
{
    public function __construct(private readonly RecordMovementService $movimentos) {}

    /**
     * @return int quantos ajustes foram gerados
     *
     * @throws ContagemInvalida
     */
    public function handle(StockCount $contagem, int $aprovadorId): int
    {
        if ($contagem->status !== StockCountStatus::AwaitingApproval) {
            throw ContagemInvalida::statusNaoPermite($contagem->status->label(), 'aprovação');
        }

        if ($contagem->counted_by !== null && $contagem->counted_by === $aprovadorId) {
            throw ContagemInvalida::aprovadorEhOContador();
        }

        $contados = $contagem->items()->contados()->get();

        if ($contados->isEmpty()) {
            throw ContagemInvalida::semItemContado();
        }

        return DB::transaction(function () use ($contagem, $aprovadorId, $contados): int {
            $ajustes = 0;

            foreach ($contados as $item) {
                /** @var StockCountItem $item */
                if (! $item->divergente()) {
                    continue;
                }

                $divergencia = (string) $item->divergencia();
                $entrada = bccomp($divergencia, '0', 3) === 1;

                $this->movimentos->handle(
                    productId: $item->product_id,
                    locationId: $contagem->location_id,
                    type: $entrada ? MovementType::AdjustmentIn : MovementType::AdjustmentOut,
                    // Sempre positiva: o sinal vem do tipo. `ltrim` do
                    // menos, e não `abs()`, porque `abs()` converteria
                    // para float e o dinheiro e a quantidade deste sistema
                    // nunca passam por float (ADR-0013).
                    qty: ltrim($divergencia, '-'),
                    notes: "Contagem {$contagem->public_id}",
                    referenceType: 'stock_count',
                    referenceId: $contagem->id,
                    createdBy: $aprovadorId,
                );

                $ajustes++;
            }

            $contagem->forceFill([
                'status' => StockCountStatus::Approved,
                'approved_by' => $aprovadorId,
                'approved_at' => now(),
            ])->save();

            return $ajustes;
        });
    }
}
