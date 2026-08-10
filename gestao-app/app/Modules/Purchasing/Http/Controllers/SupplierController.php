<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Http\Requests\SaveSupplierRequest;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cadastro de fornecedores — docs/11-Compras §4.
 *
 * Controller fino (ADR-0015): autoriza pela Policy, valida pelo
 * FormRequest, grava. Sem Service próprio de propósito — este é um CRUD
 * sem regra além da validação, e um Service que só repassasse `create`/
 * `update` seria uma camada a atravessar sem nada a dizer. O PC, que tem
 * regra, tem Services.
 */
class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $busca = $request->string('busca')->trim()->value();

        $fornecedores = Supplier::query()
            ->withCount('purchaseOrders')
            ->when($busca !== '', fn ($q) => $q->buscandoPor($busca))
            ->orderBy('legal_name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('purchasing/suppliers/index', [
            'fornecedores' => $fornecedores->through(fn (Supplier $f): array => $this->paraTela($f)),
            'filtros' => ['busca' => $busca],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Supplier::class);

        return Inertia::render('purchasing/suppliers/create');
    }

    public function store(SaveSupplierRequest $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $fornecedor = Supplier::query()->create($request->validated());

        return to_route('purchasing.suppliers.edit', $fornecedor)
            ->with('sucesso', "Fornecedor {$fornecedor->apelido()} cadastrado.");
    }

    public function edit(Supplier $supplier): Response
    {
        $this->authorize('view', $supplier);

        return Inertia::render('purchasing/suppliers/edit', [
            'fornecedor' => $this->paraTela($supplier) + [
                'notes' => $supplier->notes,
            ],
        ]);
    }

    public function update(SaveSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $supplier->update($request->validated());

        return back()->with('sucesso', 'Cadastro atualizado.');
    }

    /**
     * Inativa — nunca apaga (softDelete).
     */
    public function destroy(Supplier $supplier): RedirectResponse
    {
        $this->authorize('delete', $supplier);

        $supplier->delete();

        return to_route('purchasing.suppliers.index')
            ->with('sucesso', "{$supplier->apelido()} foi inativado. Os pedidos de compra dele continuam intactos.");
    }

    /**
     * Busca de fornecedores para o pedido de compra. Devolve o apelido —
     * é por ele que a operação reconhece de quem está comprando.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Supplier::class);

        $termo = $request->string('q')->trim()->value();

        if (mb_strlen($termo) < 2) {
            return response()->json([]);
        }

        $fornecedores = Supplier::query()
            ->buscandoPor($termo)
            ->orderBy('legal_name')
            ->limit(15)
            ->get()
            ->map(fn (Supplier $f): array => [
                'public_id' => $f->public_id,
                'nome' => $f->apelido(),
                'legal_name' => $f->legal_name,
                'document' => $f->documentoFormatado(),
                'payment_terms_days' => $f->payment_terms_days,
            ]);

        return response()->json($fornecedores);
    }

    /**
     * @return array<string, mixed>
     */
    private function paraTela(Supplier $fornecedor): array
    {
        return [
            'public_id' => $fornecedor->public_id,
            'legal_name' => $fornecedor->legal_name,
            'trade_name' => $fornecedor->trade_name,
            'nome' => $fornecedor->apelido(),
            'document' => $fornecedor->document,
            'document_formatado' => $fornecedor->documentoFormatado(),
            'email' => $fornecedor->email,
            'phone' => $fornecedor->phone,
            'payment_terms_days' => $fornecedor->payment_terms_days,
            'lead_time_days' => $fornecedor->lead_time_days,
            'pedidos_count' => $fornecedor->purchase_orders_count ?? $fornecedor->purchaseOrders()->count(),
        ];
    }
}
