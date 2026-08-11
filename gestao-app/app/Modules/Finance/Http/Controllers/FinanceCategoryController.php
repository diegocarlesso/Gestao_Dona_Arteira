<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Enums\FinanceCategoryType;
use App\Modules\Finance\Models\FinanceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plano de categorias gerencial — BR-503, docs/12-Financeiro §4.
 *
 * Diferente de `tax_profiles`: categoria errada é `rename` sem risco
 * fiscal, então esta tela não trava esperando validação externa — a
 * árvore proposta já nasce semeada (`FinanceCategorySeeder`) como default
 * editável.
 */
class FinanceCategoryController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', FinanceCategory::class);

        $categorias = FinanceCategory::query()->orderBy('name')->get();

        return Inertia::render('finance/categories/index', [
            'categorias' => $categorias->map(fn (FinanceCategory $c): array => [
                'public_id' => $c->public_id,
                'name' => $c->name,
                'type' => $c->type->value,
                'type_label' => $c->type->label(),
                'parent_public_id' => $c->parent?->public_id,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', FinanceCategory::class);

        FinanceCategory::query()->create($this->validarDados($request));

        return back()->with('sucesso', 'Categoria cadastrada.');
    }

    public function update(Request $request, FinanceCategory $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $category->update($this->validarDados($request));

        return back()->with('sucesso', 'Categoria atualizada.');
    }

    /**
     * @return array{name: string, type: string, parent_id: int|null}
     */
    private function validarDados(Request $request): array
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:'.implode(',', array_column(FinanceCategoryType::cases(), 'value'))],
            'parent_public_id' => ['nullable', 'string', 'exists:finance_categories,public_id'],
        ]);

        $pai = isset($dados['parent_public_id'])
            ? FinanceCategory::query()->where('public_id', $dados['parent_public_id'])->first()
            : null;

        return [
            'name' => $dados['name'],
            'type' => $dados['type'],
            'parent_id' => $pai?->id,
        ];
    }
}
