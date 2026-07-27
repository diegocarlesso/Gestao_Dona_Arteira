<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Console;

use App\Modules\Integrations\WooCommerce\Client;
use App\Modules\Integrations\WooCommerce\Models\StgWooProduct;
use App\Modules\Integrations\WooCommerce\Services\ExtractWooCatalog;
use Illuminate\Console\Command;
use Throwable;

/**
 * Extração do WooCommerce para o staging — docs/17-Migracao F2/§4.
 *
 * Não carrega nada no modelo do ERP: para no `stg_*`, de propósito. A
 * carga é a F4, depois do saneamento com aprovação humana — e essa
 * fronteira é o que permite rodar a extração à vontade sem risco.
 */
class ExtractWooCommand extends Command
{
    protected $signature = 'erp:migrate:extract
        {entidade=tudo : produtos, categorias ou tudo}
        {--dry-run : conta o que viria, sem gravar}
        {--pagina=1 : retoma a partir desta página}';

    protected $description = 'Extrai o catálogo do WooCommerce para as tabelas de staging';

    public function handle(Client $client, ExtractWooCatalog $extrator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $entidade = (string) $this->argument('entidade');

        // Falha em um segundo com mensagem clara, em vez de descobrir a
        // credencial errada no meio da terceira página.
        if (! $this->conferirConexao($client)) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Modo dry-run: nada será gravado.');
        }

        try {
            if (in_array($entidade, ['categorias', 'tudo'], strict: true)) {
                // Categorias primeiro, na mesma ordem da carga (F4): elas
                // são dependência dos produtos.
                $this->components->task('Categorias', function () use ($extrator, $dryRun): bool {
                    $lote = $extrator->categorias($dryRun);
                    $this->line("  {$lote->fetched} categorias · lote #{$lote->id}");

                    return true;
                });
            }

            if (in_array($entidade, ['produtos', 'tudo'], strict: true)) {
                $lote = $extrator->produtos(
                    $dryRun,
                    (int) $this->option('pagina'),
                    fn (string $linha) => $this->line("  {$linha}"),
                );

                $this->line("  lote #{$lote->id} · {$lote->fetched} itens buscados, {$lote->stored} gravados");
            }
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());
            $this->line('  O lote guarda a última página concluída — retome com --pagina=N.');

            return self::FAILURE;
        }

        if (! $dryRun) {
            $this->relatorio();
        }

        return self::SUCCESS;
    }

    private function conferirConexao(Client $client): bool
    {
        try {
            $resposta = $client->testarConexao();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return false;
        }

        if ($resposta->failed()) {
            $this->components->error("O WooCommerce respondeu {$resposta->status()} ao teste de conexão.");

            return false;
        }

        $this->components->info('Conexão com o WooCommerce confirmada.');

        return true;
    }

    /**
     * O retrato do que entrou.
     *
     * Não é enfeite: a F5 valida por contagem, e é aqui que se vê se os
     * números batem com o inventário da pasta 31 — 716 produtos, 39
     * variáveis, 77 variações, nenhum com SKU. Divergir não é
     * necessariamente erro, mas é sempre pergunta.
     */
    private function relatorio(): void
    {
        $porTipo = StgWooProduct::query()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>No staging</>', '<fg=gray>quantidade</>');

        foreach ($porTipo as $tipo => $total) {
            $this->components->twoColumnDetail((string) ($tipo ?: 'sem tipo'), (string) $total);
        }

        $semSku = StgWooProduct::query()->whereNull('sku')->count();
        $total = StgWooProduct::query()->count();

        $this->components->twoColumnDetail('<fg=yellow>sem SKU</>', "{$semSku} de {$total}");
        $this->components->twoColumnDetail(
            '<fg=yellow>sem peso</>',
            (string) StgWooProduct::query()->whereNull('weight')->count(),
        );

        $this->newLine();
        $this->line('  Nada foi carregado no catálogo ainda — isto é staging.');
        $this->line('  A carga é a fase 4, depois do saneamento com aprovação humana.');
    }
}
