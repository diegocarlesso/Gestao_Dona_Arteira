<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Console;

use App\Modules\Integrations\WooCommerce\Enums\DestinoDaTriagem;
use App\Modules\Integrations\WooCommerce\Enums\DestinoDoCliente;
use App\Modules\Integrations\WooCommerce\Models\StgWooCustomer;
use App\Modules\Integrations\WooCommerce\Models\StgWooProduct;
use App\Modules\Integrations\WooCommerce\Services\LoadWooCatalog;
use App\Modules\Integrations\WooCommerce\Services\LoadWooCustomers;
use Illuminate\Console\Command;

/**
 * Carga do staging para o ERP — docs/17-Migracao F4.
 *
 * Idempotente: rodar de novo atualiza o que já entrou (BR-706), então
 * corrigir algo no staging e recarregar não exige limpar o banco.
 *
 * No **catálogo** só entra o que foi aprovado na F3 (o SKU é imutável, e a
 * aprovação humana é a última chance de olhar). Nos **clientes** não há
 * código imutável a aprovar; a revisão acontece pelo `--dry-run`, que
 * imprime exatamente o que a carga faria — e é ele que o dono lê antes de
 * autorizar a entrada de dados pessoais de gente de verdade.
 */
class LoadWooCommand extends Command
{
    protected $signature = 'erp:migrate:load
        {entidade=catalogo : catalogo ou clientes}
        {--dry-run : mostra o que faria, sem gravar (clientes)}';

    protected $description = 'Carrega no ERP o que o staging tem aprovado/triado';

    public function handle(LoadWooCatalog $carga, LoadWooCustomers $clientes): int
    {
        return (string) $this->argument('entidade') === 'clientes'
            ? $this->carregarClientes($clientes)
            : $this->carregarCatalogo($carga);
    }

    private function carregarCatalogo(LoadWooCatalog $carga): int
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

    private function carregarClientes(LoadWooCustomers $carga): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $triados = StgWooCustomer::query()
            ->where('status_triagem', DestinoDoCliente::Cliente->value)
            ->count();

        if ($triados === 0) {
            $this->components->error('Nenhum cliente triado. Rode `erp:migrate:triage clientes` antes.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Modo dry-run: nada será gravado.');
        }

        $this->components->info("{$triados} clientes triados para carga…");

        $r = $carga->executar($dryRun);
        $verbo = $dryRun ? 'faria' : 'feito';

        $this->newLine();
        $this->components->twoColumnDetail("<fg=gray>O que a carga {$verbo}</>", '<fg=gray>cadastros</>');
        $this->components->twoColumnDetail('<fg=green>clientes novos</>', (string) $r['criados']);
        $this->components->twoColumnDetail('enriquecidos (o pedido do site já os criara)', (string) $r['enriquecidos']);
        $this->components->twoColumnDetail('mesclados com cadastro que já existia', (string) $r['mesclados']);
        $this->components->twoColumnDetail('endereços que passam a existir', (string) $r['enderecos_novos']);

        $this->pendencias($r);
        $this->listar('Merges — dois cadastros viraram um', $r['merges'], 'cyan');
        $this->listar('Documentos recusados (número já pertence a outro cliente)', $r['documentos_recusados'], 'yellow');
        $this->listar('Endereços não migrados (incompletos na origem)', $r['enderecos_recusados'], 'yellow');
        $this->listar('Diferenças preservadas — o ERP tinha valor próprio e ele venceu', $r['conflitos'], 'gray');

        $this->newLine();

        if ($dryRun) {
            $this->components->info('Nada foi gravado. Revise os números acima e rode sem --dry-run.');

            return self::SUCCESS;
        }

        $this->components->info("{$r['total_mapeado']} clientes do site estão mapeados no ERP.");
        $this->line('  A conferência da F5 é <fg=cyan>erp:migrate:validate clientes</>.');

        return self::SUCCESS;
    }

    /**
     * As lacunas que a carga carrega junto — pendência, não bloqueio.
     *
     * @param  array<string, mixed>  $r
     */
    private function pendencias(array $r): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Pendências que entram junto</>', '<fg=gray>cadastros</>');
        $this->components->twoColumnDetail('<fg=yellow>sem CPF/CNPJ</>', (string) $r['sem_documento']);
        $this->components->twoColumnDetail('<fg=yellow>CPF/CNPJ que não fecha no dígito</>', (string) $r['documento_invalido']);
        $this->components->twoColumnDetail('<fg=yellow>sem endereço</>', (string) $r['sem_endereco']);

        $this->line('  <fg=gray>Nenhuma delas impede a migração (decisão da pasta 17 F3): o cliente');
        $this->line('  entra e a lacuna aparece na listagem, barrando só a emissão de NF-e</>');
    }

    /**
     * @param  list<string>  $linhas
     */
    private function listar(string $titulo, array $linhas, string $cor): void
    {
        if ($linhas === []) {
            return;
        }

        $total = count($linhas);

        $this->newLine();
        $this->line("  <fg={$cor}>{$titulo} ({$total}):</>");

        foreach ($linhas as $linha) {
            $this->line("      {$linha}");
        }
    }
}
