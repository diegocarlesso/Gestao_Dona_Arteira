<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Jobs\EmitNfeForOrder;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Sales\Services\OrderLookupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel fiscal — o que a emissão de NF-e está fazendo (docs/14 §5).
 *
 * Mesmo papel do painel de integrações (`WooCommerce\PanelController`):
 * mostra o estado gravado, deixa reprocessar o que dá. Corrigir a causa
 * (cadastrar o NCM que falta, preencher `NFE_EMITENTE_*`) é em outra tela;
 * aqui só se vê a pendência e se manda tentar de novo.
 *
 * O nome do pedido vem por `OrderLookupService` (ADR-0020) — este módulo
 * guarda `order_id` como inteiro solto e nunca importa `Sales\Models`.
 */
class FiscalPanelController extends Controller
{
    /** As situações que a nota pode ter, na ordem do funil. */
    private const SITUACOES = ['pending', 'rejected', 'authorized', 'cancelled'];

    public function index(Request $request, OrderLookupService $pedidos): Response
    {
        $this->authorize('viewAny', Invoice::class);

        $resumo = [];
        foreach (self::SITUACOES as $situacao) {
            $resumo[$situacao] = Invoice::query()->where('status', $situacao)->count();
        }

        $filtro = $request->string('status')->trim()->value();
        $filtroValido = in_array($filtro, self::SITUACOES, true);

        $notas = Invoice::query()
            ->when($filtroValido, fn ($q) => $q->where('status', $filtro))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $resumoPedidos = $pedidos->resumos($notas->getCollection()->pluck('order_id')->all());

        return Inertia::render('fiscal/panel', [
            'resumo' => $resumo,
            'notas' => $notas->through(function (Invoice $nota) use ($resumoPedidos, $request): array {
                $pedido = $resumoPedidos[$nota->order_id] ?? null;

                return [
                    'public_id' => $nota->public_id,
                    'identificacao' => $nota->identificacao(),
                    'status' => $nota->status->value,
                    'status_label' => $nota->status->label(),
                    'pedido_numero' => $pedido?->number,
                    'pedido_public_id' => $pedido?->publicId,
                    'canal' => $pedido?->channelLabel,
                    'cliente' => $pedido?->customerName,
                    'total' => $nota->total,
                    'pendencia' => $nota->rejection_reason,
                    'created_at' => $nota->created_at->toIso8601String(),
                    'reprocessavel' => $nota->status->transmissivel() && ($request->user()?->can('reprocess', $nota) ?? false),
                ];
            }),
            'filtro' => $filtroValido ? $filtro : 'todos',
        ]);
    }

    public function reprocessar(Invoice $nota): RedirectResponse
    {
        $this->authorize('reprocess', $nota);

        if (! $nota->status->transmissivel()) {
            return back()->withErrors(['reprocessar' => 'Esta nota não aceita nova tentativa — já está autorizada ou cancelada.']);
        }

        EmitNfeForOrder::dispatch($nota->order_id);

        return back()->with('sucesso', 'Nota reenfileirada para emissão.');
    }
}
