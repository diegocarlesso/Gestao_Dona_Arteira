<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\DTO\PayableSummary;
use App\Modules\Finance\Models\Payable;

/**
 * A porta pela qual outro módulo pergunta ao Financeiro "o que a minha
 * origem gerou de dívida" — ADR-0020.
 *
 * O Compras confirma o PC e a tela precisa mostrar o título resultante. Sem
 * este Service, ou o Controller de Compras leria `Finance\Models\Payable`
 * (proibido pela fronteira, verificada no CI) ou consultaria
 * `finance_payables` direto (também proibido). A fronteira só se sustenta
 * se existir a porta.
 *
 * Devolve `PayableSummary`, nunca `Payable` — mesma regra do
 * `ProductLookupService`.
 */
class FindPayableByOriginService
{
    /**
     * Os títulos de uma origem, do mais novo para o mais antigo.
     *
     * Plural porque uma origem pode gerar mais de um título quando a
     * compra for parcelada (Gate 04) — hoje sai um só, e a tela que
     * assumisse "um" precisaria mudar de forma depois.
     *
     * @return list<PayableSummary>
     */
    public function daOrigem(string $originType, int $originId): array
    {
        return Payable::query()
            ->daOrigem($originType, $originId)
            ->with('category:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Payable $titulo): PayableSummary => new PayableSummary(
                publicId: $titulo->public_id,
                supplierName: $titulo->supplier_name,
                amount: $titulo->amount,
                dueDate: $titulo->due_date?->toDateString(),
                status: $titulo->status,
                categoryName: $titulo->category->name,
            ))
            ->all();
    }

    /**
     * Quanto esta origem já gerou de dívida, somado.
     *
     * Em `bcadd` e não `SUM()` do banco: o total volta como string com as
     * duas casas fixas, e não como float que o driver converteria pelo
     * caminho (ADR-0013).
     */
    public function totalDaOrigem(string $originType, int $originId): string
    {
        $total = '0.00';

        foreach ($this->daOrigem($originType, $originId) as $titulo) {
            $total = bcadd($total, $titulo->amount, 2);
        }

        return $total;
    }
}
