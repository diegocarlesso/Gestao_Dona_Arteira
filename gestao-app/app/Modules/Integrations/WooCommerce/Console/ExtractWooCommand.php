<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Console;

use App\Modules\Integrations\WooCommerce\Client;
use App\Modules\Integrations\WooCommerce\Models\StgWooCustomer;
use App\Modules\Integrations\WooCommerce\Models\StgWooProduct;
use App\Modules\Integrations\WooCommerce\Services\ExtractWooCatalog;
use App\Modules\Integrations\WooCommerce\Services\ExtractWooCustomers;
use Illuminate\Console\Command;
use Throwable;

/**
 * Extração do WooCommerce para o staging — docs/17-Migracao F2/§4.
 *
 * Não carrega nada no modelo do ERP: para no `stg_*`, de propósito. A
 * carga é a F4, depois do saneamento com aprovação humana — e essa
 * fronteira é o que permite rodar a extração à vontade sem risco.
 *
 * **`tudo` não inclui clientes, e isso é decisão.** Extrair pessoas é
 * copiar dado pessoal para dentro do ERP (LGPD, pasta 25 §3): tem de ser
 * um ato deliberado, escrito na linha de comando, e não um efeito colateral
 * de quem só queria reextrair o catálogo.
 */
class ExtractWooCommand extends Command
{
    protected $signature = 'erp:migrate:extract
        {entidade=tudo : produtos, categorias, clientes ou tudo (tudo = catálogo, sem clientes)}
        {--dry-run : conta o que viria, sem gravar}
        {--pagina=1 : retoma a partir desta página}';

    protected $description = 'Extrai dados do WooCommerce para as tabelas de staging';

    public function handle(Client $client, ExtractWooCatalog $extrator, ExtractWooCustomers $clientes): int
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

            if ($entidade === 'clientes') {
                $lote = $clientes->clientes(
                    $dryRun,
                    (int) $this->option('pagina'),
                    fn (string $linha) => $this->line("  {$linha}"),
                );

                $this->line("  lote #{$lote->id} · {$lote->fetched} cadastros buscados, {$lote->stored} gravados");
            }
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());
            $this->line('  O lote guarda a última página concluída — retome com --pagina=N.');

            return self::FAILURE;
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        $entidade === 'clientes' ? $this->relatorioDeClientes() : $this->relatorio();

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

    /**
     * O retrato dos cadastros que entraram.
     *
     * Os números do inventário aparecem ao lado de propósito: a pasta 31
     * §2 contou 198 cadastros e 62 compradores, e é aqui que se vê se a
     * extração conta o mesmo. Divergir não é necessariamente erro, mas é
     * sempre pergunta — e é muito mais barato fazê-la agora do que depois
     * da carga.
     */
    private function relatorioDeClientes(): void
    {
        $total = StgWooCustomer::query()->count();
        $compradores = StgWooCustomer::query()->where('orders_count', '>', 0)->count();
        $semCompra = StgWooCustomer::query()->where('orders_count', 0)->count();
        $semContagem = StgWooCustomer::query()->whereNull('orders_count')->count();

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>No staging</>', '<fg=gray>quantidade · inventário</>');
        $this->components->twoColumnDetail('cadastros', "{$total} · <fg=gray>198</>");
        $this->components->twoColumnDetail('<fg=green>com pedido</>', "{$compradores} · <fg=gray>62</>");
        $this->components->twoColumnDetail('<fg=yellow>sem nenhuma compra</>', "{$semCompra} · <fg=gray>~136</>");

        if ($semContagem > 0) {
            $this->components->twoColumnDetail('<fg=red>sem `orders_count`</>', (string) $semContagem);
        }

        $this->components->twoColumnDetail(
            'sem e-mail',
            (string) StgWooCustomer::query()->whereNull('email')->count(),
        );

        $this->newLine();
        $this->line('  Nada foi carregado no cadastro de clientes ainda — isto é staging.');
        $this->line('  A triagem é <fg=cyan>erp:migrate:triage clientes</>; só quem comprou migra');
        $this->line('  (decisão de 2026-08-10, minimização de dados pessoais).');
    }
}
