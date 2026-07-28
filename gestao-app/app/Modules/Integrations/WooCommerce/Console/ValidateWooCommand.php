<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Console;

use App\Modules\Integrations\WooCommerce\Services\ValidateWooCatalog;
use Illuminate\Console\Command;

/**
 * Validação da migração — docs/17-Migracao F5.
 *
 * A conferência que o dono assina para fechar o Gate 01: contagens
 * (origem × destino) e uma amostra estável conferida campo a campo. Sai
 * com código de erro se houver divergência, para não passar por engano
 * num pipeline.
 */
class ValidateWooCommand extends Command
{
    protected $signature = 'erp:migrate:validate {--amostra=30 : Tamanho da amostra conferida}';

    protected $description = 'Confere se o catálogo reflete fielmente a origem (F5)';

    public function handle(ValidateWooCatalog $validacao): int
    {
        $tamanho = max(1, (int) $this->option('amostra'));
        $r = $validacao->executar($tamanho);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Contagem</>', '<fg=gray>valor</>');
        $this->components->twoColumnDetail('aprovados na triagem', (string) $r['contagens']['aprovados']);
        $this->components->twoColumnDetail('mapeados no catálogo', (string) $r['contagens']['mapeados']);

        if (! $r['contagens']['bate']) {
            $this->components->error('As contagens NÃO batem — há produto aprovado que não entrou, ou vice-versa.');

            return self::FAILURE;
        }

        $this->components->info('Contagens batem: origem × destino iguais.');

        $this->newLine();
        $this->line("  Amostra estável de {$r['conferidos']} produtos, conferida campo a campo");
        $this->line('  (nome · preço de varejo · peso · cor · categoria):');
        $this->newLine();

        foreach ($r['amostra'] as $item) {
            if ($item['divergencias'] === []) {
                $this->components->twoColumnDetail(
                    "<fg=green>✓</> {$item['sku']}  {$item['nome']}",
                    "<fg=gray>woo #{$item['woo_id']}</>",
                );

                continue;
            }

            $this->components->twoColumnDetail(
                "<fg=red>✗</> {$item['sku']}  {$item['nome']}",
                "<fg=gray>woo #{$item['woo_id']}</>",
            );

            foreach ($item['divergencias'] as $divergencia) {
                $this->line("      <fg=red>→</> {$divergencia}");
            }
        }

        $this->newLine();

        if ($r['divergentes'] > 0) {
            $this->components->error("{$r['divergentes']} de {$r['conferidos']} produtos da amostra divergem da origem.");

            return self::FAILURE;
        }

        $this->components->info('Amostra sem divergências. A migração do catálogo é fiel à origem — pronto para a assinatura da F5.');
        $this->line('  Cada linha traz o `woo #id` para conferir no site, se quiser ver a olho.');

        return self::SUCCESS;
    }
}
