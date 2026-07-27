<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Repositories\DuplicateProductNames;
use App\Modules\Identity\Enums\Permission;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatório "Produtos com nome repetido" — ficha na pasta 20 §3.2.
 *
 * Nasceu como pré-requisito da contagem física: uma peça cadastrada sob
 * dois códigos tem o saldo inicial dividido ao acaso entre eles, e o
 * ledger do estoque é imutável (ADR-0008).
 *
 * Controller fino (ADR-0015): autoriza, delega a consulta ao repository,
 * responde. A definição de "repetido" mora lá, onde pode ser testada.
 */
class DuplicateNamesReportController extends Controller
{
    public function index(DuplicateProductNames $consulta): InertiaResponse
    {
        $this->authorize('viewReports', Product::class);

        $grupos = $consulta->grupos();

        return Inertia::render('catalog/reports/duplicate-names', [
            'grupos' => $grupos->map(fn (array $grupo): array => [
                'nome' => $grupo['nome'],
                'produtos' => $grupo['produtos']->map(fn (Product $p): array => [
                    'public_id' => $p->public_id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'color' => $p->color,
                    'categoria' => $p->category?->name,
                    'preco_varejo' => $p->precoVigente(PriceList::Retail)?->price,
                    'imagem' => $p->imagemPrincipal()?->paraPrevia(),
                    'imagem_alt' => $p->imagemPrincipal()?->alt,
                    'pendencias' => $p->pendencias(),
                ])->values()->all(),
            ])->all(),
            'totais' => $consulta->totais($grupos),
            // Arquivar é `catalog.manage`; ver o relatório é `reports.view`.
            // São permissões diferentes de propósito — o contador vê o
            // relatório e não mexe no catálogo —, então sem esta checagem
            // a tela ofereceria a alguém um botão que devolve 403.
            'podeArquivar' => request()->user()?->can(Permission::CatalogManage->value) ?? false,
        ]);
    }

    /**
     * CSV para quem prefere decidir fora da tela.
     *
     * `;` e BOM porque o Excel em português abre CSV separado por vírgula
     * numa coluna só, e sem o BOM come os acentos — o relatório sairia
     * com "Incens�rio" e a peça ficaria irreconhecível justamente no
     * documento que existe para reconhecê-la.
     */
    public function export(DuplicateProductNames $consulta): StreamedResponse
    {
        $this->authorize('viewReports', Product::class);

        $linhas = $consulta->linhasParaCsv($consulta->grupos());

        return response()->streamDownload(function () use ($linhas): void {
            $saida = fopen('php://output', 'w');

            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['Nome repetido', 'Código', 'Cor', 'Categoria', 'Preço de varejo', 'Pendências'], ';');

            foreach ($linhas as $linha) {
                fputcsv($saida, $linha, ';');
            }

            fclose($saida);
        }, 'produtos-com-nome-repetido.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
