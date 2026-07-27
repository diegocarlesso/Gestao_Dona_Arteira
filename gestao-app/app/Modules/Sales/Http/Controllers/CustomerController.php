<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Http\Requests\SaveCustomerRequest;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;
use App\Modules\Sales\Services\SaveCustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cadastro de clientes — BR-001, docs/10-Vendas.
 *
 * Controller fino (ADR-0015): autoriza pela Policy, valida pelo
 * FormRequest, delega ao Service, responde com Inertia.
 */
class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Customer::class);

        $busca = $request->string('busca')->trim()->value();
        $pendencia = $request->string('pendencia')->trim()->value();

        $clientes = Customer::query()
            ->withCount('addresses')
            ->when($busca !== '', fn ($q) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$busca}%")
                    ->orWhere('email', 'like', "%{$busca}%")
                    // Só dígitos na coluna, então a busca também limpa a
                    // máscara — quem digita "123.456" não acharia nada.
                    ->orWhere('doc', 'like', '%'.preg_replace('/\D/', '', $busca).'%')
            ))
            ->when($pendencia === 'sem_doc', fn ($q) => $q->whereNull('doc'))
            ->when($pendencia === 'sem_endereco', fn ($q) => $q->doesntHave('addresses'))
            ->when($pendencia === 'atacado', fn ($q) => $q->atacadistas())
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('sales/customers/index', [
            'clientes' => $clientes->through(fn (Customer $c): array => $this->paraTela($c)),
            'filtros' => ['busca' => $busca, 'pendencia' => $pendencia],
            'contagens' => [
                'sem_doc' => Customer::query()->whereNull('doc')->count(),
                'sem_endereco' => Customer::query()->doesntHave('addresses')->count(),
                'atacado' => Customer::query()->atacadistas()->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Customer::class);

        return Inertia::render('sales/customers/create', $this->opcoes());
    }

    public function store(SaveCustomerRequest $request, SaveCustomerService $service): RedirectResponse
    {
        $this->authorize('create', Customer::class);

        $cliente = $service->handle(
            null,
            $request->safe()->except('enderecos'),
            $request->validated('enderecos') ?? [],
        );

        return to_route('sales.customers.edit', $cliente)
            ->with('sucesso', "Cliente {$cliente->name} cadastrado.");
    }

    public function edit(Customer $customer): Response
    {
        $this->authorize('view', $customer);

        $customer->load('addresses');

        return Inertia::render('sales/customers/edit', [
            'cliente' => $this->paraTela($customer) + [
                'state_registration' => $customer->state_registration,
                'notes' => $customer->notes,
                'lgpd_consent_at' => $customer->lgpd_consent_at?->toIso8601String(),
                'origem' => $customer->origin->label(),
                'enderecos' => $customer->addresses->map(fn (CustomerAddress $e): array => [
                    'public_id' => $e->public_id,
                    'label' => $e->label,
                    'zip' => $e->cepFormatado(),
                    'street' => $e->street,
                    'number' => $e->number,
                    'complement' => $e->complement,
                    'district' => $e->district,
                    'city' => $e->city,
                    'state' => $e->state,
                    'is_default_shipping' => $e->is_default_shipping,
                    'is_default_billing' => $e->is_default_billing,
                ])->values(),
            ],
        ] + $this->opcoes());
    }

    public function update(SaveCustomerRequest $request, Customer $customer, SaveCustomerService $service): RedirectResponse
    {
        $this->authorize('update', $customer);

        $service->handle(
            $customer,
            $request->safe()->except('enderecos'),
            $request->validated('enderecos') ?? [],
        );

        return back()->with('sucesso', 'Cadastro atualizado.');
    }

    /**
     * Inativa — nunca apaga (softDelete).
     */
    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        $customer->delete();

        return to_route('sales.customers.index')
            ->with('sucesso', "{$customer->name} foi inativado. O histórico de compras continua intacto.");
    }

    /**
     * @return array<string, mixed>
     */
    private function paraTela(Customer $cliente): array
    {
        return [
            'public_id' => $cliente->public_id,
            'type' => $cliente->type->value,
            'type_label' => $cliente->type->label(),
            'name' => $cliente->name,
            'doc' => $cliente->doc,
            'doc_formatado' => $cliente->documentoFormatado(),
            'email' => $cliente->email,
            'phone' => $cliente->phone,
            'is_wholesale' => $cliente->is_wholesale,
            'enderecos_count' => $cliente->addresses_count ?? $cliente->addresses()->count(),
            // A lacuna aparece antes da venda, e não durante: sem
            // documento não sai nota, e descobrir isso com o cliente no
            // balcão é tarde.
            'pendencias' => $cliente->pendencias(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function opcoes(): array
    {
        return [
            'tipos' => array_map(
                fn (CustomerType $t): array => ['valor' => $t->value, 'rotulo' => $t->label(), 'documento' => $t->documentoEsperado()],
                CustomerType::cases(),
            ),
        ];
    }
}
