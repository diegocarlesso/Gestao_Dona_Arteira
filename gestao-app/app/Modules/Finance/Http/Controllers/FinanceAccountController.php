<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Enums\FinanceAccountType;
use App\Modules\Finance\Models\FinanceAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cadastro de contas financeiras (caixa, banco, gateway) — docs/12 §3.
 *
 * Controller fino (ADR-0015): sem Service próprio — é CRUD sem regra além
 * da validação, mesmo caso do `SupplierController`.
 */
class FinanceAccountController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', FinanceAccount::class);

        $contas = FinanceAccount::query()->orderBy('name')->get();

        return Inertia::render('finance/accounts/index', [
            'contas' => $contas->map(fn (FinanceAccount $c): array => [
                'public_id' => $c->public_id,
                'name' => $c->name,
                'type' => $c->type->value,
                'type_label' => $c->type->label(),
                'notes' => $c->notes,
                'saldo' => $c->saldo(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', FinanceAccount::class);

        $dados = $this->validarDados($request);

        FinanceAccount::query()->create($dados);

        return back()->with('sucesso', 'Conta financeira cadastrada.');
    }

    public function update(Request $request, FinanceAccount $account): RedirectResponse
    {
        $this->authorize('update', $account);

        $account->update($this->validarDados($request));

        return back()->with('sucesso', 'Conta financeira atualizada.');
    }

    /**
     * @return array{name: string, type: string, notes: string|null}
     */
    private function validarDados(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:'.implode(',', array_column(FinanceAccountType::cases(), 'value'))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
