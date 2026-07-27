<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Services\ProductLookupService;
use App\Modules\Inventory\Enums\StockCountStatus;
use App\Modules\Inventory\Exceptions\ContagemInvalida;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountItem;
use Illuminate\Support\Facades\DB;

/**
 * Abre uma contagem e **congela a lista** — BR-205, pasta 09 §5.
 *
 * O congelamento é o ponto: a divergência é entre o que a prateleira
 * tinha e o que o sistema dizia *naquele instante*. Sem o snapshot, uma
 * venda ocorrida durante a contagem viraria divergência, e o ajuste
 * apagaria uma expedição real.
 */
class OpenStockCountService
{
    public function __construct(private readonly ProductLookupService $catalogo) {}

    /**
     * @throws ContagemInvalida
     */
    public function handle(Location $local, ?int $abertaPor = null, ?string $notes = null): StockCount
    {
        $aberta = StockCount::query()
            ->where('location_id', $local->id)
            ->whereIn('status', [StockCountStatus::Counting->value, StockCountStatus::AwaitingApproval->value])
            ->exists();

        if ($aberta) {
            throw ContagemInvalida::jaExisteContagemAberta($local->name);
        }

        return DB::transaction(function () use ($local, $abertaPor, $notes): StockCount {
            $contagem = StockCount::query()->create([
                'location_id' => $local->id,
                'status' => StockCountStatus::Counting,
                'notes' => $notes,
                'counted_by' => $abertaPor,
            ]);

            $ids = $this->catalogo->idsAtivos();

            // Saldos numa consulta só: são 754 produtos, e perguntar o
            // saldo de cada um seria 754 idas ao banco para montar uma
            // tela que ainda vai levar horas para ser preenchida à mão.
            $saldos = InventoryBalance::query()
                ->where('location_id', $local->id)
                ->whereIn('product_id', $ids)
                ->pluck('qty_on_hand', 'product_id');

            $linhas = [];
            $agora = now();

            foreach ($ids as $id) {
                $linhas[] = [
                    'stock_count_id' => $contagem->id,
                    'product_id' => $id,
                    // Produto sem linha de saldo nunca se moveu: o sistema
                    // diz zero, e é isso que a contagem vai contradizer ou
                    // confirmar.
                    'qty_system' => $saldos[$id] ?? '0.000',
                    'qty_counted' => null,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }

            foreach (array_chunk($linhas, 500) as $lote) {
                StockCountItem::query()->insert($lote);
            }

            return $contagem;
        });
    }
}
