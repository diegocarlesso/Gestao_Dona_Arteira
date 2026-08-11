<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Exceptions\TituloInvalido;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\RegisterSettlementService;
use App\Modules\Finance\Services\ReverseSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contas a pagar e a receber — docs/12-Financeiro §3/§6.
 *
 * Uma tela só para os dois lados (aba `tipo=payable|receivable`), mesmo
 * raciocínio do `RegisterSettlementService`: a regra é idêntica dos dois
 * lados, então duas telas quase iguais só duplicariam manutenção.
 */
class TitleController extends Controller
{
    private const TIPOS = ['receivable', 'payable'];

    public function __construct(
        private readonly RegisterSettlementService $baixas,
        private readonly ReverseSettlementService $estornos,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Payable::class);

        $tipo = $request->string('tipo')->trim()->value();
        $tipo = in_array($tipo, self::TIPOS, true) ? $tipo : 'receivable';
        $somenteAbertos = $request->boolean('abertos', true);

        $classe = $tipo === 'payable' ? Payable::class : Receivable::class;

        $titulos = $classe::query()
            ->with(['category', 'settlements.account'])
            ->when($somenteAbertos, fn ($q) => $q->abertos())
            ->orderByRaw('due_date IS NULL, due_date ASC')
            ->paginate(25)
            ->withQueryString();

        $hoje = now()->startOfDay();

        return Inertia::render('finance/titles/index', [
            'tipo' => $tipo,
            'somenteAbertos' => $somenteAbertos,
            'titulos' => $titulos->through(function (Payable|Receivable $titulo) use ($hoje, $tipo): array {
                // `abs()`: o Carbon usado aqui devolve diferença com sinal
                // por padrão — sem isso, todo vencido caía sempre na
                // primeira faixa de aging (número negativo é `<= 15`).
                $diasVencido = $titulo->due_date !== null && $titulo->due_date->lt($hoje)
                    ? abs((int) $hoje->diffInDays($titulo->due_date))
                    : null;

                return [
                    'public_id' => $titulo->public_id,
                    'nome' => $tipo === 'payable' ? $titulo->supplier_name : $titulo->customer_name,
                    'categoria' => $titulo->category->name,
                    'valor' => $titulo->amount,
                    'saldo_aberto' => $titulo->saldoAberto(),
                    'vencimento' => $titulo->due_date?->toDateString(),
                    'dias_vencido' => $diasVencido,
                    'faixa_aging' => $this->faixaAging($diasVencido),
                    'status' => $titulo->status,
                    'baixas' => $this->baixasParaTela($titulo),
                ];
            }),
            'contas' => FinanceAccount::query()->orderBy('name')->get(['id', 'public_id', 'name']),
        ]);
    }

    public function settle(Request $request, string $tipo, string $publicId): RedirectResponse
    {
        $this->authorize('settle', Payable::class);

        $titulo = $this->resolverTitulo($tipo, $publicId);

        $dados = $request->validate([
            'account_id' => ['required', 'integer', 'exists:finance_accounts,id'],
            'amount' => ['required', 'string'],
            'settled_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->baixas->handle(
                titleType: $tipo,
                titleId: $titulo->id,
                accountId: (int) $dados['account_id'],
                amount: (string) $dados['amount'],
                settledAt: (string) $dados['settled_at'],
                notes: $dados['notes'] ?? null,
            );
        } catch (TituloInvalido $e) {
            return back()->withErrors(['baixa' => $e->getMessage()]);
        }

        return back()->with('sucesso', 'Baixa registrada.');
    }

    public function reverse(Request $request, FinanceSettlement $settlement): RedirectResponse
    {
        $this->authorize('reverse', Payable::class);

        $dados = $request->validate([
            'motivo' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->estornos->handle($settlement->id, (string) $dados['motivo']);
        } catch (TituloInvalido $e) {
            return back()->withErrors(['estorno' => $e->getMessage()]);
        }

        return back()->with('sucesso', 'Baixa estornada.');
    }

    /**
     * @throws TituloInvalido
     */
    private function resolverTitulo(string $tipo, string $publicId): Payable|Receivable
    {
        $classe = $tipo === 'payable' ? Payable::class : Receivable::class;
        $titulo = $classe::query()->where('public_id', $publicId)->first();

        if ($titulo === null) {
            throw TituloInvalido::tituloInexistente($tipo, 0);
        }

        return $titulo;
    }

    private function faixaAging(?int $diasVencido): ?string
    {
        if ($diasVencido === null) {
            return null;
        }

        return match (true) {
            $diasVencido <= 15 => '1-15',
            $diasVencido <= 30 => '16-30',
            default => '30+',
        };
    }

    /**
     * `->all()`, não a Collection crua: embutir uma `Collection<int, array{
     * ...}>` dentro do array-shape de outro método (que já é um union
     * Payable|Receivable) faz o PHPStan comparar o `TValue` do template de
     * forma invariante e reprovar uma substituição que é segura — um
     * `list` puro não tem essa restrição.
     *
     * @return list<array{public_id: string, valor: string, data: string, conta: string, estorno: bool, notas: string|null}>
     */
    private function baixasParaTela(Payable|Receivable $titulo): array
    {
        return $titulo->settlements->map(fn (FinanceSettlement $b): array => [
            'public_id' => $b->public_id,
            'valor' => $b->amount,
            'data' => $b->settled_at->toDateString(),
            'conta' => $b->account->name,
            'estorno' => $b->estorno(),
            'notas' => $b->notes,
        ])->all();
    }
}
