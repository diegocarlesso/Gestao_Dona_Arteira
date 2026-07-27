<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\DTO\ProductSummary;
use App\Modules\Catalog\Services\ProductLookupService;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Repositories\StockPosition;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * As telas de leitura do estoque — posição e extrato.
 *
 * O nome do produto vem do `ProductLookupService` do Catálogo, nunca de
 * um `join` em `products`: o ADR-0020 proíbe consultar a tabela de outro
 * módulo, e a proibição é o que mantém possível trocar o Catálogo sem
 * caçar `join`s espalhados por seis módulos.
 */
class StockController extends Controller
{
    public function index(Request $request, StockPosition $posicao, ProductLookupService $catalogo): Response
    {
        $this->authorize('viewAny', InventoryBalance::class);

        $busca = $request->string('busca')->trim()->value();
        $local = $request->string('local')->trim()->value();
        $somenteComSaldo = $request->boolean('com_saldo');

        $locais = Location::query()->active()->orderBy('name')->get(['id', 'public_id', 'name']);
        $localSelecionado = $locais->firstWhere('public_id', $local) ?? Location::padrao();

        $saldos = $posicao->posicao(
            locationId: $localSelecionado?->id,
            productIds: $busca === '' ? null : $catalogo->idsPorTermo($busca),
            somenteComSaldo: $somenteComSaldo,
        );

        $produtos = $catalogo->resumos($saldos->getCollection()->pluck('product_id')->all());

        return Inertia::render('inventory/position', [
            'saldos' => $saldos->through(fn (InventoryBalance $s): array => [
                'produto' => $this->produtoParaTela($produtos[$s->product_id] ?? null),
                'local' => $s->location?->name,
                'on_hand' => $s->qty_on_hand,
                'reserved' => $s->qty_reserved,
                'disponivel' => $s->disponivel(),
                'avg_cost' => $s->avg_cost,
                'abaixo_do_minimo' => $this->abaixoDoMinimo($s, $produtos[$s->product_id] ?? null),
            ]),
            'locais' => $locais->map(fn (Location $l): array => [
                'public_id' => $l->public_id,
                'name' => $l->name,
            ]),
            'filtros' => [
                'busca' => $busca,
                'local' => $localSelecionado?->public_id,
                'com_saldo' => $somenteComSaldo,
            ],
        ]);
    }

    public function statement(
        Request $request,
        string $produto,
        StockPosition $posicao,
        ProductLookupService $catalogo,
    ): Response {
        $this->authorize('viewAny', InventoryBalance::class);

        $resumo = $catalogo->resumoPorPublicId($produto);

        if ($resumo === null) {
            throw new NotFoundHttpException;
        }

        $locais = Location::query()->active()->orderBy('name')->get(['id', 'public_id', 'name']);
        $local = $locais->firstWhere('public_id', $request->string('local')->value()) ?? Location::padrao();

        if ($local === null) {
            throw new NotFoundHttpException;
        }

        $movimentos = $posicao->extrato($resumo->id, $local->id);

        $saldo = InventoryBalance::query()
            ->where('product_id', $resumo->id)
            ->where('location_id', $local->id)
            ->first();

        return Inertia::render('inventory/statement', [
            'produto' => $this->produtoParaTela($resumo),
            'local' => ['public_id' => $local->public_id, 'name' => $local->name],
            'locais' => $locais->map(fn (Location $l): array => [
                'public_id' => $l->public_id,
                'name' => $l->name,
            ]),
            // Peça que nunca se moveu não tem linha de saldo — e a tela
            // mostra zeros em vez de "—", porque zero é a resposta certa
            // para "quanto tem?" e travessão sugere informação faltando.
            'saldo' => $saldo === null
                ? ['on_hand' => '0.000', 'reserved' => '0.000', 'disponivel' => '0.000', 'avg_cost' => '0.00']
                : [
                    'on_hand' => $saldo->qty_on_hand,
                    'reserved' => $saldo->qty_reserved,
                    'disponivel' => $saldo->disponivel(),
                    'avg_cost' => $saldo->avg_cost,
                ],
            'movimentos' => $movimentos->through(fn (InventoryMovement $m): array => [
                'public_id' => $m->public_id,
                'tipo' => $m->type->label(),
                'entrada' => $m->entrada(),
                'qty' => $m->qty,
                'saldo_apos' => $m->getAttribute('saldo_apos'),
                'unit_cost' => $m->unit_cost,
                'notes' => $m->notes,
                'occurred_at' => $m->occurred_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * @return array<string, string|null>|null
     */
    private function produtoParaTela(?ProductSummary $resumo): ?array
    {
        return $resumo === null ? null : [
            'public_id' => $resumo->publicId,
            'sku' => $resumo->sku,
            'name' => $resumo->name,
            'color' => $resumo->color,
            'unit' => $resumo->unit,
        ];
    }

    /**
     * O mínimo é do produto e o saldo é do local — a comparação só existe
     * cruzando os dois, e nenhum dos lados pode alcançar o outro sozinho.
     */
    private function abaixoDoMinimo(InventoryBalance $saldo, ?ProductSummary $resumo): bool
    {
        if ($resumo?->minStock === null) {
            return false;
        }

        return bccomp($saldo->qty_on_hand, $resumo->minStock, 3) === -1;
    }
}
