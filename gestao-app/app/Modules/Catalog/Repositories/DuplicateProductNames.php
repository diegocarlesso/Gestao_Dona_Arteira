<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Repositories;

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Collection;

/**
 * Produtos que dividem o mesmo nome — relatório da pasta 20 §3.2.
 *
 * Consulta nomeada pelo negócio, e é por isso que ela mora aqui e não
 * dentro do controller (ADR-0015: repository só onde agrega). A pergunta
 * "temos a mesma peça cadastrada duas vezes?" tem definição própria,
 * documentada na ficha, e a definição precisa de um lugar onde possa ser
 * testada sozinha.
 *
 * **Só produtos ativos.** Arquivar o repetido faz o grupo desaparecer do
 * relatório, e é isso que transforma a lista num trabalho que termina:
 * ela encolhe conforme alguém decide, e zero significa acabou.
 */
class DuplicateProductNames
{
    /**
     * Os grupos de nome repetido, cada um com os produtos que o formam.
     *
     * @return Collection<int, array{nome: string, produtos: Collection<int, Product>}>
     */
    public function grupos(): Collection
    {
        $nomes = Product::query()
            ->active()
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('name');

        if ($nomes->isEmpty()) {
            return collect();
        }

        // Duas consultas em vez de uma: a primeira responde *quais* nomes
        // se repetem, a segunda traz as fichas. Uma janela (`COUNT(*)
        // OVER`) faria em uma só, mas devolveria linha achatada — e o
        // relatório precisa dos produtos agrupados, com imagem e preço,
        // que é exatamente o que o Eloquent já sabe hidratar.
        $produtos = Product::query()
            ->active()
            ->whereIn('name', $nomes)
            ->with(['category:id,name', 'prices', 'images'])
            ->orderBy('name')
            ->orderBy('sku')
            ->get();

        /** @var array<int, array{nome: string, produtos: Collection<int, Product>}> $grupos */
        $grupos = [];

        // Por `mb_strtolower`, e não por `groupBy('name')`: a colação do
        // banco é `utf8mb4_unicode_ci`, então o `GROUP BY` acima já juntou
        // "Buda Sidarta" e "BUDA SIDARTA" — mas o `groupBy` do Collection
        // compara string do jeito do PHP, que é sensível à caixa, e
        // tornaria a separar o que o banco uniu. As duas metades da mesma
        // consulta precisam concordar sobre o que é "o mesmo nome".
        foreach ($produtos->groupBy(fn (Product $p): string => mb_strtolower($p->name)) as $doGrupo) {
            $primeiro = $doGrupo->first();

            if ($primeiro === null) {
                continue;
            }

            $grupos[] = ['nome' => $primeiro->name, 'produtos' => collect($doGrupo->all())];
        }

        return collect($grupos);
    }

    /**
     * Quantos produtos e quantos grupos — o tamanho do trabalho restante.
     *
     * @param  Collection<int, array{nome: string, produtos: Collection<int, Product>}>  $grupos
     * @return array{grupos: int, produtos: int}
     */
    public function totais(Collection $grupos): array
    {
        return [
            'grupos' => $grupos->count(),
            'produtos' => $grupos->sum(fn (array $grupo): int => $grupo['produtos']->count()),
        ];
    }

    /**
     * As linhas do CSV — uma por produto, com o nome do grupo repetido.
     *
     * Repetir o nome em cada linha é intencional: quem abre no Excel
     * ordena, filtra e recorta, e linha que depende da de cima para fazer
     * sentido quebra na primeira ordenação.
     *
     * @param  Collection<int, array{nome: string, produtos: Collection<int, Product>}>  $grupos
     * @return list<list<string>>
     */
    public function linhasParaCsv(Collection $grupos): array
    {
        $linhas = [];

        foreach ($grupos as $grupo) {
            foreach ($grupo['produtos'] as $produto) {
                $linhas[] = [
                    $grupo['nome'],
                    $produto->sku,
                    (string) $produto->color,
                    (string) $produto->category?->name,
                    $this->emReais($produto->precoVigente(PriceList::Retail)?->price),
                    implode(', ', $produto->pendencias()),
                ];
            }
        }

        return $linhas;
    }

    /**
     * Vírgula decimal e sem separador de milhar — o Excel brasileiro lê
     * "89,90" como número e "89.90" como texto.
     */
    private function emReais(?string $valor): string
    {
        return $valor === null ? '' : number_format((float) $valor, 2, ',', '');
    }
}
