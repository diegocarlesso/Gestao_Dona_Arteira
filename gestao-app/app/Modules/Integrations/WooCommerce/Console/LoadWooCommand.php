<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Console;

use App\Modules\Integrations\WooCommerce\Enums\DestinoDaTriagem;
use App\Modules\Integrations\WooCommerce\Models\StgWooProduct;
use App\Modules\Integrations\WooCommerce\Services\LoadWooCatalog;
use Illuminate\Console\Command;

/**
 * Carga do staging para o catálogo — docs/17-Migracao F4.
 *
 * Só entra o que foi aprovado na F3. Idempotente: rodar de novo atualiza
 * o que já entrou (BR-706), então corrigir algo no staging e recarregar
 * não exige limpar o banco.
 */
class LoadWooCommand extends Command
{
    protected $signature = 'erp:migrate:load';

    protected $description = 'Carrega no catálogo os produtos aprovados do staging';

    public function handle(LoadWooCatalog $carga): int
    {
        $aprovados = StgWooProduct::query()
            ->where('status_triagem', DestinoDaTriagem::Produto->value)
            ->whereNotNull('aprovado_em')
            ->count();

        if ($aprovados === 0) {
            $this->components->error('Nada aprovado. Rode `erp:migrate:approve` antes.');

            return self::FAILURE;
        }

        $this->components->info("Carregando {$aprovados} produtos aprovados…");

        $contagem = $carga->executar();

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Carregado</>', '<fg=gray>quantidade</>');
        $this->components->twoColumnDetail('categorias criadas', (string) $contagem['categorias']);
        $this->components->twoColumnDetail('produtos criados', (string) $contagem['produtos']);
        $this->components->twoColumnDetail('preços de varejo', (string) $contagem['precos']);

        if ($contagem['peso_recusado'] > 0) {
            $this->newLine();
            $this->components->warn("{$contagem['peso_recusado']} produtos ficaram sem peso.");
            $this->line('  O valor da origem era implausível (peça de gesso não pesa 970 kg —');
            $this->line('  são gramas digitadas em campo de quilo). Peso errado quebraria o');
            $this->line('  frete em silêncio; sem peso, o produto aparece no relatório de');
            $this->line('  cadastro incompleto, que é onde alguém olha.');
        }

        $this->newLine();
        $this->components->info("{$contagem['total_mapeado']} produtos do WooCommerce estão no catálogo.");
        $this->line('  Nenhum tem preço de atacado — a lista nunca existiu em sistema nenhum.');
        $this->line('  Use o filtro "sem preço de atacado" na tela de produtos para preencher.');

        return self::SUCCESS;
    }
}
