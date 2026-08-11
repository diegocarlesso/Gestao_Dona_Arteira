<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Services\ProductLookupService;
use App\Modules\Finance\DTO\PayableSummary;
use App\Modules\Finance\Exceptions\TituloInvalido;
use App\Modules\Finance\Services\FindPayableByOriginService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Exceptions\PedidoDeCompraInvalido;
use App\Modules\Purchasing\Http\Requests\SavePurchaseOrderRequest;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseOrderStatusHistory;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\CancelPurchaseOrderService;
use App\Modules\Purchasing\Services\ConfirmPurchaseOrderService;
use App\Modules\Purchasing\Services\SavePurchaseOrderService;
use App\Modules\Purchasing\Services\SendPurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedidos de compra — BR-402, docs/11-Compras (Fase 1).
 *
 * Controller fino (ADR-0015): autoriza, valida, delega ao Service de cada
 * transição. O nome da peça vem por Service do Catálogo e o título a pagar
 * por Service do Financeiro (ADR-0020) — este módulo não lê o model de
 * nenhum dos dois.
 */
class PurchaseOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $status = $request->string('status')->trim()->value();

        $pedidos = PurchaseOrder::query()
            ->with('supplier:id,legal_name,trade_name')
            ->withCount('items')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('number')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('purchasing/purchase-orders/index', [
            'pedidos' => $pedidos->through(fn (PurchaseOrder $p): array => [
                'public_id' => $p->public_id,
                'number' => $p->number,
                'fornecedor' => $p->supplier->apelido(),
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'itens' => $p->items_count,
                'total' => $p->total,
                'expected_delivery_at' => $p->expected_delivery_at?->toDateString(),
                'created_at' => $p->created_at?->toIso8601String(),
            ]),
            'filtros' => ['status' => $status],
            'status' => collect(PurchaseOrderStatus::cases())->map(fn (PurchaseOrderStatus $s): array => [
                'valor' => $s->value, 'rotulo' => $s->label(),
            ]),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', PurchaseOrder::class);

        return Inertia::render('purchasing/purchase-orders/create');
    }

    public function store(SavePurchaseOrderRequest $request, SavePurchaseOrderService $service): RedirectResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        try {
            $pedido = $service->handle(
                null,
                $this->dadosDoPedido($request),
                $this->itensDoPedido($request),
                $request->user()?->id,
            );
        } catch (PedidoDeCompraInvalido $e) {
            return back()->withErrors(['pedido' => $e->getMessage()])->withInput();
        }

        return to_route('purchasing.purchase_orders.edit', $pedido)
            ->with('sucesso', "Pedido de compra #{$pedido->number} aberto.");
    }

    public function edit(
        PurchaseOrder $purchase_order,
        ProductLookupService $catalogo,
        FindPayableByOriginService $titulos,
    ): Response {
        $this->authorize('view', $purchase_order);

        $purchase_order->load(['items', 'supplier', 'statusHistory']);
        $produtos = $catalogo->resumos($purchase_order->items->pluck('product_id')->all());

        return Inertia::render('purchasing/purchase-orders/edit', [
            'pedido' => [
                'public_id' => $purchase_order->public_id,
                'number' => $purchase_order->number,
                'status' => $purchase_order->status->value,
                'status_label' => $purchase_order->status->label(),
                'rascunho' => $purchase_order->status->rascunho(),
                'confirmavel' => $purchase_order->status->confirmavel(),
                'cancelavel' => $purchase_order->status->cancelavel(),
                'pode_enviar' => $purchase_order->status === PurchaseOrderStatus::Draft,
                'subtotal' => $purchase_order->subtotal,
                'freight' => $purchase_order->freight,
                'total' => $purchase_order->total,
                'issued_at' => $purchase_order->issued_at?->toDateString(),
                'expected_delivery_at' => $purchase_order->expected_delivery_at?->toDateString(),
                'notes' => $purchase_order->notes,
                'fornecedor' => [
                    'public_id' => $purchase_order->supplier->public_id,
                    'nome' => $purchase_order->supplier->apelido(),
                    'document' => $purchase_order->supplier->documentoFormatado(),
                    'payment_terms_days' => $purchase_order->supplier->payment_terms_days,
                ],
                'itens' => $purchase_order->items->map(function (PurchaseOrderItem $i) use ($produtos): array {
                    $p = $produtos[$i->product_id] ?? null;

                    return [
                        'product' => $p?->publicId,
                        'sku' => $p?->sku,
                        'name' => $p?->name,
                        'unit' => $p?->unit,
                        'qty' => $i->qty,
                        'unit_cost' => $i->unit_cost,
                        'line_total' => $i->line_total,
                    ];
                })->values(),
                'historico' => $purchase_order->statusHistory->map(fn (PurchaseOrderStatusHistory $h): array => [
                    'de' => $h->from_status?->label(),
                    'para' => $h->to_status->label(),
                    'motivo' => $h->reason,
                    'quando' => $h->created_at?->toIso8601String(),
                ]),
            ],
            // O título que a confirmação gerou (BR-402). Perguntado ao
            // Financeiro por Service — o Compras não conhece `Payable`
            // (ADR-0020).
            'titulos' => array_map(fn (PayableSummary $t): array => [
                'public_id' => $t->publicId,
                'supplier_name' => $t->supplierName,
                'amount' => $t->amount,
                'due_date' => $t->dueDate,
                'status' => $t->status,
                'categoria' => $t->categoryName,
            ], $titulos->daOrigem(ConfirmPurchaseOrderService::ORIGEM, $purchase_order->id)),
            'podeConfirmar' => request()->user()?->can('confirm', $purchase_order) ?? false,
            'podeCancelar' => request()->user()?->can('cancel', $purchase_order) ?? false,
        ]);
    }

    public function update(
        SavePurchaseOrderRequest $request,
        PurchaseOrder $purchase_order,
        SavePurchaseOrderService $service,
    ): RedirectResponse {
        $this->authorize('update', $purchase_order);

        try {
            $service->handle(
                $purchase_order,
                $this->dadosDoPedido($request),
                $this->itensDoPedido($request),
                $request->user()?->id,
            );
        } catch (PedidoDeCompraInvalido $e) {
            return back()->withErrors(['pedido' => $e->getMessage()]);
        }

        return back()->with('sucesso', 'Pedido de compra salvo.');
    }

    public function send(Request $request, PurchaseOrder $purchase_order, SendPurchaseOrderService $service): RedirectResponse
    {
        $this->authorize('update', $purchase_order);

        try {
            $service->handle($purchase_order, $request->user()?->id);
        } catch (PedidoDeCompraInvalido $e) {
            return back()->withErrors(['transicao' => $e->getMessage()]);
        }

        return back()->with('sucesso', "PC #{$purchase_order->number} marcado como enviado ao fornecedor.");
    }

    public function confirm(Request $request, PurchaseOrder $purchase_order, ConfirmPurchaseOrderService $service): RedirectResponse
    {
        $this->authorize('confirm', $purchase_order);

        try {
            $service->handle($purchase_order, $request->user()?->id);
        } catch (PedidoDeCompraInvalido|TituloInvalido $e) {
            // `TituloInvalido` chega quando o Financeiro recusa o título
            // (categoria padrão não semeada, por exemplo). A mensagem dele
            // diz o que fazer — repassada como está, em vez de virar um
            // "erro ao confirmar" que não ajuda ninguém.
            return back()->withErrors(['transicao' => $e->getMessage()]);
        }

        return back()->with('sucesso', "PC #{$purchase_order->number} confirmado — conta a pagar gerada.");
    }

    public function cancel(Request $request, PurchaseOrder $purchase_order, CancelPurchaseOrderService $service): RedirectResponse
    {
        $this->authorize('cancel', $purchase_order);

        $dados = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        try {
            $service->handle($purchase_order, $dados['motivo'], $request->user()?->id);
        } catch (PedidoDeCompraInvalido $e) {
            return back()->withErrors(['transicao' => $e->getMessage()]);
        }

        return back()->with('sucesso', "PC #{$purchase_order->number} cancelado.");
    }

    /**
     * Busca de peças para adicionar item ao PC.
     *
     * Usa os resumos do Catálogo (nome/código/unidade) e **não** o preço:
     * na compra o valor é o negociado com o fornecedor, digitado à mão —
     * mostrar o preço de venda ao lado só induziria ao erro.
     */
    public function searchProducts(Request $request, ProductLookupService $catalogo): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $termo = $request->string('q')->trim()->value();

        if (mb_strlen($termo) < 2) {
            return response()->json([]);
        }

        return response()->json($catalogo->buscarParaCompra($termo));
    }

    /**
     * @return array{supplier_id: int, freight: string|null, issued_at: string|null, expected_delivery_at: string|null, notes: string|null}
     */
    private function dadosDoPedido(SavePurchaseOrderRequest $request): array
    {
        $supplierId = Supplier::query()
            ->where('public_id', $request->validated('supplier'))
            ->value('id');

        return [
            'supplier_id' => (int) $supplierId,
            'freight' => $request->validated('freight') === null ? null : (string) $request->validated('freight'),
            'issued_at' => $request->validated('issued_at'),
            'expected_delivery_at' => $request->validated('expected_delivery_at'),
            'notes' => $request->validated('notes'),
        ];
    }

    /**
     * @return list<array{product: string, qty: string, unit_cost: string}>
     */
    private function itensDoPedido(SavePurchaseOrderRequest $request): array
    {
        /** @var list<array{product: string, qty: mixed, unit_cost: mixed}> $itens */
        $itens = $request->validated('itens') ?? [];

        return array_map(fn (array $linha): array => [
            'product' => $linha['product'],
            'qty' => (string) $linha['qty'],
            'unit_cost' => (string) $linha['unit_cost'],
        ], $itens);
    }
}
