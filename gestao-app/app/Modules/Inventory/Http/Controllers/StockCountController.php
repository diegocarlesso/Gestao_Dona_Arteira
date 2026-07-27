<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Services\ProductLookupService;
use App\Modules\Identity\Enums\Permission;
use App\Modules\Inventory\Enums\StockCountStatus;
use App\Modules\Inventory\Exceptions\ContagemInvalida;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Services\ApproveStockCountService;
use App\Modules\Inventory\Services\OpenStockCountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contagem física — BR-205, docs/09-Estoque §5.
 *
 * É por esta tela que o saldo inicial dos 754 produtos migrados entra no
 * ERP: nem o Woo nem o legado têm saldo confiável, então o estoque nasce
 * de gente olhando a prateleira.
 */
class StockCountController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', InventoryBalance::class);

        $contagens = StockCount::query()
            ->with('location:id,name')
            ->withCount([
                'items',
                'items as items_contados_count' => fn ($q) => $q->whereNotNull('qty_counted'),
            ])
            ->orderByDesc('id')
            ->paginate(20);

        return Inertia::render('inventory/counts/index', [
            'contagens' => $contagens->through(fn (StockCount $c): array => [
                'public_id' => $c->public_id,
                'local' => $c->location?->name,
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'total' => $c->items_count,
                // Por `getAttribute`: `items_contados_count` é apelido de
                // `withCount`, não propriedade do model — declará-lo no
                // docblock faria o model anunciar um campo que só existe
                // quando esta consulta específica o pede.
                'contados' => (int) $c->getAttribute('items_contados_count'),
                'created_at' => $c->created_at?->toIso8601String(),
                'approved_at' => $c->approved_at?->toIso8601String(),
            ]),
            'locais' => Location::query()->active()->orderBy('name')->get(['public_id', 'name']),
            'podeAjustar' => $this->podeAjustar(),
        ]);
    }

    public function store(Request $request, OpenStockCountService $abrir): RedirectResponse
    {
        $this->authorize('adjust', InventoryBalance::class);

        $dados = $request->validate([
            'local' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $local = Location::query()->where('public_id', $dados['local'])->firstOrFail();

        try {
            $contagem = $abrir->handle($local, $request->user()?->id, $dados['notes'] ?? null);
        } catch (ContagemInvalida $e) {
            return back()->withErrors(['local' => $e->getMessage()]);
        }

        return to_route('inventory.counts.show', $contagem)
            ->with('sucesso', 'Contagem aberta. A lista está congelada — pode contar com calma.');
    }

    public function show(StockCount $contagem, ProductLookupService $catalogo, Request $request): Response
    {
        $this->authorize('viewAny', InventoryBalance::class);

        $somenteDivergentes = $request->boolean('divergentes');
        $somentePendentes = $request->boolean('pendentes');

        $itens = $contagem->items()
            ->when($somentePendentes, fn ($q) => $q->whereNull('qty_counted'))
            ->when($somenteDivergentes, fn ($q) => $q->whereNotNull('qty_counted')
                ->whereColumn('qty_counted', '!=', 'qty_system'))
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $produtos = $catalogo->resumos($itens->getCollection()->pluck('product_id')->all());

        return Inertia::render('inventory/counts/show', [
            'contagem' => [
                'public_id' => $contagem->public_id,
                'local' => $contagem->location?->name,
                'status' => $contagem->status->value,
                'status_label' => $contagem->status->label(),
                'notes' => $contagem->notes,
                'aberta' => $contagem->status->aberta(),
                'created_at' => $contagem->created_at?->toIso8601String(),
            ],
            'resumo' => $this->resumoDa($contagem),
            'itens' => $itens->through(function (StockCountItem $i) use ($produtos): array {
                $produto = $produtos[$i->product_id] ?? null;

                return [
                    'id' => $i->id,
                    'sku' => $produto?->sku,
                    'name' => $produto?->name,
                    'color' => $produto?->color,
                    'qty_system' => $i->qty_system,
                    'qty_counted' => $i->qty_counted,
                    'divergencia' => $i->divergencia(),
                ];
            }),
            'filtros' => ['divergentes' => $somenteDivergentes, 'pendentes' => $somentePendentes],
            'podeAjustar' => $this->podeAjustar(),
            // Quem contou não aprova (BR-205). A tela esconde o botão em
            // vez de deixar a pessoa clicar e levar um erro — mas o
            // serviço recusa de qualquer forma, porque esconder não é
            // proteger.
            'podeAprovar' => $this->podeAjustar()
                && $contagem->status === StockCountStatus::AwaitingApproval
                && $contagem->counted_by !== request()->user()?->id,
        ]);
    }

    /**
     * Grava as quantidades contadas de uma página.
     */
    public function count(Request $request, StockCount $contagem): RedirectResponse
    {
        $this->authorize('adjust', InventoryBalance::class);

        if (! $contagem->status->aberta()) {
            return back()->withErrors(['status' => 'Esta contagem não aceita mais quantidades.']);
        }

        $dados = $request->validate([
            'quantidades' => ['required', 'array'],
            'quantidades.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        foreach ($dados['quantidades'] as $itemId => $quantidade) {
            $contagem->items()
                ->whereKey((int) $itemId)
                // String vazia limpa a contagem daquela linha: quem digitou
                // errado precisa poder voltar ao estado "não contei", que
                // não é o mesmo que "contei zero".
                ->update(['qty_counted' => ($quantidade === null || $quantidade === '') ? null : $quantidade]);
        }

        return back()->with('sucesso', 'Contagem salva.');
    }

    public function close(StockCount $contagem): RedirectResponse
    {
        $this->authorize('adjust', InventoryBalance::class);

        if (! $contagem->status->aberta()) {
            return back()->withErrors(['status' => 'Esta contagem já foi encerrada.']);
        }

        $contagem->forceFill([
            'status' => StockCountStatus::AwaitingApproval,
            'closed_at' => now(),
        ])->save();

        return back()->with('sucesso', 'Contagem encerrada. Agora ela precisa da aprovação de outra pessoa.');
    }

    public function approve(Request $request, StockCount $contagem, ApproveStockCountService $aprovar): RedirectResponse
    {
        $this->authorize('adjust', InventoryBalance::class);

        try {
            $ajustes = $aprovar->handle($contagem, (int) $request->user()?->id);
        } catch (ContagemInvalida $e) {
            return back()->withErrors(['aprovacao' => $e->getMessage()]);
        }

        return back()->with('sucesso', $ajustes === 0
            ? 'Contagem aprovada. Nenhuma divergência — o sistema já estava certo.'
            : "Contagem aprovada. {$ajustes} ajuste(s) lançados no estoque.");
    }

    public function cancel(StockCount $contagem): RedirectResponse
    {
        $this->authorize('adjust', InventoryBalance::class);

        if ($contagem->status->encerrada()) {
            return back()->withErrors(['status' => 'Contagem aprovada ou cancelada não muda mais.']);
        }

        $contagem->forceFill(['status' => StockCountStatus::Cancelled])->save();

        return to_route('inventory.counts.index')->with('sucesso', 'Contagem cancelada.');
    }

    /**
     * @return array<string, int>
     */
    private function resumoDa(StockCount $contagem): array
    {
        return [
            'total' => $contagem->items()->count(),
            'contados' => $contagem->items()->whereNotNull('qty_counted')->count(),
            'divergentes' => $contagem->items()
                ->whereNotNull('qty_counted')
                ->whereColumn('qty_counted', '!=', 'qty_system')
                ->count(),
        ];
    }

    private function podeAjustar(): bool
    {
        return request()->user()?->can(Permission::InventoryAdjust->value) ?? false;
    }
}
