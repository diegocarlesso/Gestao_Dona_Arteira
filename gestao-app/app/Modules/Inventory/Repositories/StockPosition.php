<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
// O concreto, não o contrato `Contracts\Pagination\LengthAwarePaginator`:
// `through()` e `getCollection()` só existem na classe, e é por elas que
// o controller transforma as linhas para a tela.
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * As duas perguntas de leitura do estoque — pasta 09 §8, pasta 20.
 *
 * "O que temos agora?" (posição) e "por que o saldo está assim?"
 * (extrato). A segunda é a tela de depuração número 1 do módulo, e a
 * pasta 09 manda investir nela desde o Gate 01 — antes que haja saldo
 * errado para investigar.
 */
class StockPosition
{
    /**
     * A posição, filtrável.
     *
     * @param  list<int>|null  $productIds  restrição vinda da busca do Catálogo
     * @return LengthAwarePaginator<int, InventoryBalance>
     */
    public function posicao(
        ?int $locationId = null,
        ?array $productIds = null,
        bool $somenteComSaldo = false,
    ): LengthAwarePaginator {
        return InventoryBalance::query()
            ->with('location:id,name')
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            // `null` é "sem filtro"; lista vazia é "a busca não achou
            // nada" e precisa devolver zero linhas. Tratar os dois como
            // iguais faria uma busca sem resultado mostrar o estoque
            // inteiro, que é o oposto do que a pessoa pediu.
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds ?? []))
            ->when($somenteComSaldo, fn ($q) => $q->where('qty_on_hand', '>', 0))
            ->orderByDesc('qty_on_hand')
            ->orderBy('product_id')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * O extrato de um produto num local, com o saldo **depois de cada
     * movimento**.
     *
     * O saldo corrente é o que torna o extrato utilizável: sem ele a tela
     * mostra uma pilha de entradas e saídas e deixa a soma para a cabeça
     * de quem lê, que é exatamente o trabalho que ela deveria evitar.
     *
     * Calculado de trás para frente, a partir do saldo atual: some o que
     * veio depois desta linha e subtraia. Custa uma agregação por página,
     * e não uma varredura do ledger inteiro — que é o que uma soma
     * acumulada desde o começo custaria depois de alguns anos.
     *
     * @return LengthAwarePaginator<int, InventoryMovement>
     */
    public function extrato(int $productId, int $locationId): LengthAwarePaginator
    {
        $pagina = InventoryMovement::query()
            ->extrato($productId, $locationId)
            ->paginate(30)
            ->withQueryString();

        $saldoAtual = (string) (InventoryBalance::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->value('qty_on_hand') ?? '0');

        /** @var Collection<int, InventoryMovement> $itens */
        $itens = $pagina->getCollection();

        $maisRecente = $itens->first();

        // Quanto se moveu **depois** da primeira linha desta página. Na
        // página 1 é zero; nas seguintes é o que já foi exibido acima.
        $posteriores = $maisRecente === null ? '0' : (string) (InventoryMovement::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('id', '>', $maisRecente->id)
            ->sum('qty'));

        $corrente = bcsub($saldoAtual, $posteriores, 3);

        foreach ($itens as $movimento) {
            // Cada linha carrega o saldo que existia logo depois dela;
            // descendo a página, desfaz-se o movimento para chegar ao
            // saldo da linha seguinte, que é mais antiga.
            $movimento->setAttribute('saldo_apos', $corrente);
            $corrente = bcsub($corrente, $movimento->qty, 3);
        }

        return $pagina;
    }
}
