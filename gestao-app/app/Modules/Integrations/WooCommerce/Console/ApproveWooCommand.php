<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Console;

use App\Modules\Integrations\WooCommerce\Enums\DestinoDaTriagem;
use App\Modules\Integrations\WooCommerce\Models\StgWooProduct;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aprovação dos SKUs propostos — docs/17-Migracao F3.
 *
 * O passo que a pasta 17 exige entre propor e carregar: "gerar SKU
 * proposto → **aprovação humana** em lote". O código é imutável depois de
 * criado (BR-002), então esta é a última chance de olhar.
 *
 * Aprovar não carrega nada — só destranca a carga.
 */
class ApproveWooCommand extends Command
{
    protected $signature = 'erp:migrate:approve
        {--completos : aprova só o que não tem pendência de peso nem preço}
        {--sku= : aprova um SKU proposto específico}
        {--desfazer : retira a aprovação}';

    protected $description = 'Aprova os SKUs propostos, liberando a carga (fase 4)';

    public function handle(): int
    {
        $alvo = $this->alvo();
        $total = (clone $alvo)->count();

        if ($total === 0) {
            $this->components->warn('Nada a fazer — nenhum item se encaixa nesse filtro.');

            return self::SUCCESS;
        }

        if ($this->option('desfazer')) {
            (clone $alvo)->update(['aprovado_em' => null]);
            $this->components->info("{$total} itens voltaram a pendentes.");

            return self::SUCCESS;
        }

        $this->resumo($alvo, $total);

        if (! $this->confirm("Aprovar {$total} itens?", default: false)) {
            $this->components->warn('Nada aprovado.');

            return self::SUCCESS;
        }

        (clone $alvo)->update(['aprovado_em' => now()]);

        $aprovados = StgWooProduct::query()->whereNotNull('aprovado_em')->count();
        $pendentes = StgWooProduct::query()
            ->where('status_triagem', DestinoDaTriagem::Produto->value)
            ->whereNull('aprovado_em')
            ->count();

        $this->newLine();
        $this->components->info("{$total} aprovados agora · {$aprovados} no total · {$pendentes} ainda pendentes");
        $this->line('  A carga é <fg=cyan>erp:migrate:load</> — só entra o que está aprovado.');

        return self::SUCCESS;
    }

    /**
     * @return Builder<StgWooProduct>
     */
    private function alvo(): Builder
    {
        $query = StgWooProduct::query()
            // Só o que vira produto: invólucro e variação vazia não são
            // carregados, então aprová-los não significaria nada.
            ->where('status_triagem', DestinoDaTriagem::Produto->value)
            ->whereNotNull('sku_proposto');

        $query->when(
            ! $this->option('desfazer'),
            fn (Builder $q) => $q->whereNull('aprovado_em'),
            fn (Builder $q) => $q->whereNotNull('aprovado_em'),
        );

        if ($sku = $this->option('sku')) {
            return $query->where('sku_proposto', $sku);
        }

        if ($this->option('completos')) {
            // Deixa de fora quem tem lacuna que a venda vai cobrar: peso
            // quebra a cotação de frete, e sem preço não há o que cobrar.
            // Permite carregar a maior parte do catálogo enquanto a equipe
            // completa o resto.
            return $query
                ->whereNotNull('weight')
                ->whereNotNull('regular_price')
                ->where('regular_price', '!=', '');
        }

        return $query;
    }

    /**
     * @param  Builder<StgWooProduct>  $alvo
     */
    private function resumo(Builder $alvo, int $total): void
    {
        $semPeso = (clone $alvo)->whereNull('weight')->count();
        $semPreco = (clone $alvo)->where(function (Builder $q) {
            $q->whereNull('regular_price')->orWhere('regular_price', '');
        })->count();

        $primeiro = (clone $alvo)->orderBy('sku_proposto')->value('sku_proposto');
        $ultimo = (clone $alvo)->orderByDesc('sku_proposto')->value('sku_proposto');

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>A aprovar</>', '<fg=gray>itens</>');
        $this->components->twoColumnDetail('produtos', (string) $total);
        $this->components->twoColumnDetail('faixa de SKU', "{$primeiro} … {$ultimo}");

        if ($semPeso > 0) {
            $this->components->twoColumnDetail('<fg=yellow>sem peso</>', (string) $semPeso);
        }

        if ($semPreco > 0) {
            $this->components->twoColumnDetail('<fg=yellow>sem preço</>', (string) $semPreco);
        }

        if ($semPeso > 0 || $semPreco > 0) {
            $this->newLine();
            $this->line('  <fg=gray>Lacuna não impede a carga — o produto entra e fica sinalizado');
            $this->line('  na listagem. Para deixar os incompletos de fora: --completos</>');
        }

        $this->newLine();
        $this->line('  <fg=yellow>O SKU é imutável depois de criado (BR-002).</> Aprovar é dizer');
        $this->line('  que estes códigos valem para sempre.');
    }
}
