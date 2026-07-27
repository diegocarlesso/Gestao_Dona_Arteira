<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Console;

use App\Modules\Integrations\WooCommerce\Enums\DestinoDaTriagem;
use App\Modules\Integrations\WooCommerce\Models\StgWooProduct;
use App\Modules\Integrations\WooCommerce\Services\TriageWooProducts;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saneamento do catálogo — docs/17-Migracao F3.
 *
 * Classifica o staging e propõe os SKUs. Nada é carregado: a F4 só aceita
 * o que estiver **aprovado**, e a aprovação é humana por decisão da
 * pasta 17 — a BR-002 torna o código imutável.
 */
class TriageWooCommand extends Command
{
    protected $signature = 'erp:migrate:triage
        {--duplicados : lista os títulos repetidos, que precisam de olhos}';

    protected $description = 'Classifica o staging do Woo e propõe os SKUs para aprovação';

    public function handle(TriageWooProducts $triagem): int
    {
        if (StgWooProduct::query()->doesntExist()) {
            $this->components->error('O staging está vazio. Rode `erp:migrate:extract` antes.');

            return self::FAILURE;
        }

        if ($this->option('duplicados')) {
            $this->listarDuplicados();

            return self::SUCCESS;
        }

        $contagem = $triagem->executar();

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Destino na carga</>', '<fg=gray>itens</>');

        foreach (DestinoDaTriagem::cases() as $destino) {
            $total = $contagem[$destino->value] ?? 0;

            if ($total === 0) {
                continue;
            }

            $this->components->twoColumnDetail(
                $destino->viraProduto() ? "<fg=green>{$destino->label()}</>" : $destino->label(),
                (string) $total,
            );
        }

        $viraProduto = $contagem[DestinoDaTriagem::Produto->value] ?? 0;
        $primeiro = StgWooProduct::query()->whereNotNull('sku_proposto')->orderBy('sku_proposto')->value('sku_proposto');
        $ultimo = StgWooProduct::query()->whereNotNull('sku_proposto')->orderByDesc('sku_proposto')->value('sku_proposto');

        $this->newLine();
        $this->components->info("SKUs propostos: {$primeiro} … {$ultimo} ({$viraProduto} produtos)");

        $this->pendencias();

        $this->newLine();
        $this->line('  Nada foi carregado. A carga (fase 4) só aceita itens aprovados —');
        $this->line('  o SKU é imutável depois de criado (BR-002), então a conferência');
        $this->line('  humana acontece antes, não depois.');
        $this->newLine();
        $this->line('  Para ver o que exige decisão: <fg=cyan>erp:migrate:triage --duplicados</>');

        return self::SUCCESS;
    }

    /**
     * O que a triagem automática não resolve.
     *
     * Números, não adjetivos: a equipe precisa saber quanto trabalho tem
     * pela frente antes de começar.
     */
    private function pendencias(): void
    {
        $produtos = StgWooProduct::query()->where('status_triagem', DestinoDaTriagem::Produto->value);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Exige decisão humana</>', '<fg=gray>itens</>');

        // Duplicata de verdade e variação com rótulo genérico são coisas
        // diferentes, e misturá-las inflaria o trabalho manual: a segunda
        // o saneamento já resolve compondo o nome.
        $reais = $this->gruposDuplicados(apenasAnuncios: true);

        $this->components->twoColumnDetail(
            '<fg=yellow>anúncios repetidos</>',
            $reais->sum('n').' produtos em '.$reais->count().' grupos',
        );

        $this->components->twoColumnDetail(
            '<fg=yellow>sem peso</>',
            (string) (clone $produtos)->whereNull('weight')->count(),
        );

        $this->components->twoColumnDetail(
            '<fg=yellow>sem preço</>',
            (string) (clone $produtos)->where(function ($q) {
                $q->whereNull('regular_price')->orWhere('regular_price', '');
            })->count(),
        );
    }

    private function listarDuplicados(): void
    {
        $grupos = $this->gruposDuplicados(apenasAnuncios: true);

        $this->newLine();
        $this->components->info("{$grupos->count()} anúncios aparecem mais de uma vez ({$grupos->sum('n')} produtos)");
        $this->line('  Cada grupo é a mesma peça recriada no site, com cores diferentes.');
        $this->line('  Manter separados é a decisão de 2026-07-27 — fundir depois é arquivar os repetidos.');
        $this->newLine();
        $this->line('  <fg=gray>Variações de kit com nome repetido ("KIT COMPLETO" ×18) não entram');
        $this->line('  nesta lista: não são duplicata, e a triagem já compõe o nome delas</>');
        $this->newLine();

        foreach ($grupos as $grupo) {
            $this->line("  <fg=yellow>{$grupo->n}×</> {$grupo->name}");

            $itens = StgWooProduct::query()
                ->where('name', $grupo->name)
                ->where('type', '!=', 'variation')
                ->where('status_triagem', DestinoDaTriagem::Produto->value)
                ->orderBy('woo_id')
                ->get();

            foreach ($itens as $item) {
                $this->line(sprintf(
                    '      %s  woo=%-6d  %s',
                    $item->sku_proposto ?? '—',
                    $item->woo_id,
                    $this->coresDe($item) ?: '(sem cor)',
                ));
            }
        }
    }

    /**
     * As cores que o anúncio lista, já legíveis.
     *
     * Percorrido à mão em vez de `collect()->firstWhere()`: o payload é
     * JSON solto, então cada nível pode ser qualquer coisa, e o laço
     * explícito é o que deixa isso verdadeiro para quem lê e para a
     * análise estática.
     */
    private function coresDe(StgWooProduct $item): string
    {
        foreach ((array) ($item->payload['attributes'] ?? []) as $atributo) {
            if (is_array($atributo) && ($atributo['slug'] ?? '') === 'pa_cor') {
                return implode(', ', (array) ($atributo['options'] ?? []));
            }
        }

        return '';
    }

    /**
     * Agregação, não entidade — daí o query builder e não o Eloquent.
     *
     * `StgWooProduct::selectRaw('name, count(*)')` devolveria models
     * meio-preenchidos, fingindo ser linhas de staging que não são. O
     * `stdClass` diz a verdade sobre o que isto é: contagem por título.
     *
     * @param  bool  $apenasAnuncios  exclui variações, cujos nomes repetidos
     *                                são rótulo de opção, não duplicata
     * @return Collection<int, \stdClass>
     */
    private function gruposDuplicados(bool $apenasAnuncios = false): Collection
    {
        return DB::table('stg_woo_products')
            ->selectRaw('name, count(*) as n')
            ->where('status_triagem', DestinoDaTriagem::Produto->value)
            ->when($apenasAnuncios, fn ($q) => $q->where('type', '!=', 'variation'))
            ->groupBy('name')
            ->havingRaw('count(*) > 1')
            ->orderByDesc('n')
            ->get();
    }
}
