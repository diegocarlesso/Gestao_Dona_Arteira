<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Services\ProductLookupService;
use App\Modules\Inventory\Exceptions\ReservaInvalida;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Exceptions\PedidoInvalido;
use App\Modules\Sales\Http\Requests\SaveOrderRequest;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Sales\Models\OrderStatusHistory;
use App\Modules\Sales\Services\CancelOrderService;
use App\Modules\Sales\Services\ConfirmOrderService;
use App\Modules\Sales\Services\FulfillmentService;
use App\Modules\Sales\Services\SaveOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedidos — BR-303, docs/10-Vendas. Corte 2 do Gate 02.
 *
 * Controller fino (ADR-0015): autoriza, valida, delega ao Service certo
 * de cada transição. O nome do produto nos itens vem por Service do
 * Catálogo (ADR-0020), como no estoque.
 */
class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Order::class);

        $status = $request->string('status')->trim()->value();

        $pedidos = Order::query()
            ->with('customer:id,name')
            ->withCount('items')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('number')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('sales/orders/index', [
            'pedidos' => $pedidos->through(fn (Order $p): array => [
                'public_id' => $p->public_id,
                'number' => $p->number,
                'cliente' => $p->customer !== null ? $p->customer->name : 'Consumidor',
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'itens' => $p->items_count,
                'total' => $p->total,
                'created_at' => $p->created_at?->toIso8601String(),
            ]),
            'filtros' => ['status' => $status],
            'status' => collect(OrderStatus::cases())->map(fn (OrderStatus $s): array => [
                'valor' => $s->value, 'rotulo' => $s->label(),
            ]),
        ]);
    }

    public function store(SaveOrderRequest $request, SaveOrderService $service): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $pedido = $service->handle(
            null,
            $this->dadosDoPedido($request),
            $request->validated('itens') ?? [],
            $request->user()?->id,
        );

        return to_route('sales.orders.edit', $pedido)->with('sucesso', "Pedido #{$pedido->number} aberto.");
    }

    public function edit(Order $order, ProductLookupService $catalogo): Response
    {
        $this->authorize('view', $order);

        $order->load(['items', 'customer', 'history']);
        $produtos = $catalogo->resumos($order->items->pluck('product_id')->all());

        return Inertia::render('sales/orders/edit', [
            'pedido' => [
                'public_id' => $order->public_id,
                'number' => $order->number,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'rascunho' => $order->status->rascunho(),
                'cancelavel' => $order->status->cancelavel(),
                'canal' => $order->channel->label(),
                'notes' => $order->notes,
                'subtotal' => $order->subtotal,
                'total' => $order->total,
                'cliente' => $order->customer === null ? null : [
                    'public_id' => $order->customer->public_id,
                    'name' => $order->customer->name,
                ],
                'itens' => $order->items->map(function (OrderItem $i) use ($produtos): array {
                    $p = $produtos[$i->product_id] ?? null;

                    return [
                        'product' => $p?->publicId,
                        'sku' => $p?->sku,
                        'name' => $p?->name,
                        'qty' => $i->qty,
                        'unit_price' => $i->unit_price,
                        'line_total' => $i->lineTotal(),
                        'note' => $i->note,
                    ];
                })->values(),
                'historico' => $order->history->map(fn (OrderStatusHistory $h): array => [
                    'de' => $h->from_status?->label(),
                    'para' => $h->to_status->label(),
                    'motivo' => $h->reason,
                    'quando' => $h->created_at?->toIso8601String(),
                ]),
            ],
            'podeConfirmar' => request()->user()?->can('confirm', $order) ?? false,
            'podeCancelar' => request()->user()?->can('cancel', $order) ?? false,
        ]);
    }

    public function update(SaveOrderRequest $request, Order $order, SaveOrderService $service): RedirectResponse
    {
        $this->authorize('update', $order);

        try {
            $service->handle($order, $this->dadosDoPedido($request), $request->validated('itens') ?? [], $request->user()?->id);
        } catch (PedidoInvalido $e) {
            return back()->withErrors(['pedido' => $e->getMessage()]);
        }

        return back()->with('sucesso', 'Pedido salvo.');
    }

    public function confirm(Order $order, ConfirmOrderService $service): RedirectResponse
    {
        $this->authorize('confirm', $order);

        try {
            $service->handle($order, request()->user()?->id);
        } catch (PedidoInvalido|ReservaInvalida $e) {
            // A reserva insuficiente vem do Estoque com a mensagem que diz
            // qual produto e quanto falta — repassada como está.
            return back()->withErrors(['confirmacao' => $e->getMessage()]);
        }

        return back()->with('sucesso', "Pedido #{$order->number} confirmado — estoque reservado.");
    }

    public function cancel(Request $request, Order $order, CancelOrderService $service): RedirectResponse
    {
        $this->authorize('cancel', $order);

        $dados = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        try {
            $service->handle($order, $dados['motivo'], $request->user()?->id);
        } catch (PedidoInvalido $e) {
            return back()->withErrors(['cancelamento' => $e->getMessage()]);
        }

        return back()->with('sucesso', "Pedido #{$order->number} cancelado.");
    }

    public function separar(Request $request, Order $order, FulfillmentService $service): RedirectResponse
    {
        $this->authorize('fulfill', $order);

        try {
            $service->iniciarSeparacao($order, $request->user()?->id);
        } catch (PedidoInvalido $e) {
            return back()->withErrors(['fulfillment' => $e->getMessage()]);
        }

        return back()->with('sucesso', "Pedido #{$order->number} em separação.");
    }

    public function embalar(Request $request, Order $order, FulfillmentService $service): RedirectResponse
    {
        $this->authorize('fulfill', $order);

        try {
            $service->embalar($order, $request->user()?->id);
        } catch (PedidoInvalido $e) {
            return back()->withErrors(['fulfillment' => $e->getMessage()]);
        }

        return back()->with('sucesso', "Pedido #{$order->number} embalado.");
    }

    public function expedir(Request $request, Order $order, FulfillmentService $service): RedirectResponse
    {
        $this->authorize('fulfill', $order);

        $dados = $request->validate([
            'tracking_code' => ['nullable', 'string', 'max:100'],
            'carrier' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $service->expedir(
                $order,
                $dados['tracking_code'] ?? null,
                $dados['carrier'] ?? null,
                $request->user()?->id,
            );
        } catch (PedidoInvalido|ReservaInvalida $e) {
            // ReservaInvalida não deveria acontecer no Embalado (a reserva
            // está ativa desde a confirmação), mas se acontecer a mensagem
            // diz qual peça travou — repassada como está.
            return back()->withErrors(['fulfillment' => $e->getMessage()]);
        }

        return back()->with('sucesso', "Pedido #{$order->number} expedido.");
    }

    public function entregar(Request $request, Order $order, FulfillmentService $service): RedirectResponse
    {
        $this->authorize('fulfill', $order);

        try {
            $service->entregar($order, $request->user()?->id);
        } catch (PedidoInvalido $e) {
            return back()->withErrors(['fulfillment' => $e->getMessage()]);
        }

        return back()->with('sucesso', "Pedido #{$order->number} entregue.");
    }

    /**
     * Busca de produtos para adicionar item. Devolve o que a origem manda
     * procurar (nome/código/cor — a regra é do Catálogo, ADR-0020).
     */
    public function searchProducts(Request $request, ProductLookupService $catalogo): JsonResponse
    {
        $this->authorize('create', Order::class);

        $termo = $request->string('q')->trim()->value();

        if (mb_strlen($termo) < 2) {
            return response()->json([]);
        }

        return response()->json($catalogo->buscarParaVenda($termo));
    }

    /**
     * Busca de clientes para atribuir ao pedido (o balcão pode deixar em
     * "Consumidor"). Mesma tabela que a listagem de clientes, filtrada por
     * nome/documento.
     */
    public function searchCustomers(Request $request): JsonResponse
    {
        $this->authorize('create', Order::class);

        $termo = $request->string('q')->trim()->value();

        if (mb_strlen($termo) < 2) {
            return response()->json([]);
        }

        $clientes = Customer::query()
            ->where(fn ($q) => $q->where('name', 'like', "%{$termo}%")
                ->orWhere('doc', 'like', '%'.preg_replace('/\D/', '', $termo).'%'))
            ->orderBy('name')
            ->limit(15)
            ->get(['public_id', 'name', 'doc'])
            ->map(fn (Customer $c): array => [
                'public_id' => $c->public_id,
                'name' => $c->name,
                'doc' => $c->documentoFormatado(),
            ]);

        return response()->json($clientes);
    }

    /**
     * @return array{customer_id: int|null, notes: string|null}
     */
    private function dadosDoPedido(SaveOrderRequest $request): array
    {
        $customerPublicId = $request->validated('customer');

        $customerId = $customerPublicId === null
            ? null
            : Customer::query()->where('public_id', $customerPublicId)->value('id');

        return [
            'customer_id' => $customerId === null ? null : (int) $customerId,
            'notes' => $request->validated('notes'),
        ];
    }
}
