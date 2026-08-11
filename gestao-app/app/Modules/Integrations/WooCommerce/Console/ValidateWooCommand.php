<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Console;

use App\Modules\Integrations\WooCommerce\Services\ValidateWooCatalog;
use App\Modules\Integrations\WooCommerce\Services\ValidateWooCustomers;
use Illuminate\Console\Command;

/**
 * Validação da migração — docs/17-Migracao F5.
 *
 * A conferência que o dono assina: contagens (origem × destino) e uma
 * amostra estável conferida campo a campo. Sai com código de erro se
 * houver divergência, para não passar por engano num pipeline.
 */
class ValidateWooCommand extends Command
{
    protected $signature = 'erp:migrate:validate
        {entidade=catalogo : catalogo ou clientes}
        {--amostra= : Tamanho da amostra conferida (padrão: 30 produtos, 20 clientes)}';

    protected $description = 'Confere se o ERP reflete fielmente a origem (F5)';

    public function handle(ValidateWooCatalog $catalogo, ValidateWooCustomers $clientes): int
    {
        return (string) $this->argument('entidade') === 'clientes'
            ? $this->validarClientes($clientes)
            : $this->validarCatalogo($catalogo);
    }

    private function validarCatalogo(ValidateWooCatalog $validacao): int
    {
        $tamanho = $this->tamanhoDaAmostra(30);
        $r = $validacao->executar($tamanho);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Contagem</>', '<fg=gray>valor</>');
        $this->components->twoColumnDetail('aprovados na triagem', (string) $r['contagens']['aprovados']);
        $this->components->twoColumnDetail('mapeados no catálogo', (string) $r['contagens']['mapeados']);

        // Banco vazio não é migração fiel — é migração ausente. "0 = 0"
        // batendo faria a validação mandar assinar a F5 sobre o nada; a
        // F5 roda em PRODUÇÃO, e rodar no banco errado é o engano provável.
        if ($r['contagens']['aprovados'] === 0) {
            $this->newLine();
            $this->components->error('Nada aprovado para validar neste banco.');
            $this->line('  A F5 roda em <fg=yellow>produção</>, onde a migração aconteceu. Zero');
            $this->line('  aprovados aqui quer dizer banco vazio (dev/local), não catálogo fiel.');

            return self::FAILURE;
        }

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

    private function validarClientes(ValidateWooCustomers $validacao): int
    {
        $r = $validacao->executar($this->tamanhoDaAmostra(20));

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Contagem</>', '<fg=gray>valor</>');
        $this->components->twoColumnDetail('triados para migrar', (string) $r['contagens']['triados']);
        $this->components->twoColumnDetail('mapeados no cadastro', (string) $r['contagens']['mapeados']);

        foreach ($r['rejeitados'] as $motivo => $total) {
            if ($total > 0) {
                $this->components->twoColumnDetail("<fg=gray>rejeitados: {$motivo}</>", (string) $total);
            }
        }

        if ($r['contagens']['triados'] === 0) {
            $this->newLine();
            $this->components->error('Nenhum cliente triado para validar neste banco.');
            $this->line('  A F5 roda em <fg=yellow>produção</>, onde a migração aconteceu. Zero');
            $this->line('  triados aqui quer dizer banco vazio (dev/local), não cadastro fiel.');

            return self::FAILURE;
        }

        if ($r['contagens']['pendentes'] > 0) {
            $this->newLine();
            $this->components->error("{$r['contagens']['pendentes']} cadastros continuam pendentes de triagem.");
            $this->line('  Eles não migraram e não aparecem em contagem nenhuma — decidir antes');
            $this->line('  de assinar, senão a assinatura cobre um número que ignora gente.');

            return self::FAILURE;
        }

        if (! $r['contagens']['bate']) {
            $this->components->error('As contagens NÃO batem — há cliente triado que não entrou, ou vice-versa.');

            return self::FAILURE;
        }

        $this->components->info('Contagens batem: todo cliente triado está mapeado no ERP.');

        $total = $r['contagens']['mapeados_total'];

        if ($total > $r['contagens']['mapeados']) {
            $this->line("  <fg=gray>Há {$total} mapeamentos de cliente ao todo — mais que os triados, e isso");
            $this->line('  é normal: o pedido do site mapeia o comprador desde o Gate 02, e quem');
            $this->line('  apagou a conta no Woo depois de comprar não está mais no staging.</>');
        }

        $this->newLine();
        $this->line("  Amostra estável de {$r['conferidos']} clientes, conferida campo a campo");
        $this->line('  (nome · e-mail · telefone · documento · endereços):');
        $this->newLine();

        foreach ($r['amostra'] as $item) {
            $marca = $item['divergencias'] === [] ? '<fg=green>✓</>' : '<fg=red>✗</>';

            $this->components->twoColumnDetail(
                "{$marca} {$item['nome']}",
                "<fg=gray>woo #{$item['woo_id']}</>",
            );

            foreach ($item['divergencias'] as $divergencia) {
                $this->line("      <fg=red>→</> {$divergencia}");
            }

            foreach ($item['preservados'] as $preservado) {
                $this->line("      <fg=yellow>·</> {$preservado}");
            }
        }

        $this->newLine();

        if ($r['divergentes'] > 0) {
            $this->components->error("{$r['divergentes']} de {$r['conferidos']} clientes da amostra divergem da origem.");

            return self::FAILURE;
        }

        $this->components->info('Amostra sem divergências. A migração de clientes é fiel à origem — pronto para a assinatura da F5.');
        $this->line('  As linhas em <fg=yellow>amarelo</> não são erro: são diferenças em que o valor do');
        $this->line('  ERP venceu o do site (regra 5 do projeto). Valem uma olhada, não um veto.');

        return self::SUCCESS;
    }

    /**
     * O padrão muda por entidade — 30 produtos, 20 clientes, como o plano
     * original da pasta 17 previa ("30 produtos/20 clientes/20 pedidos").
     */
    private function tamanhoDaAmostra(int $padrao): int
    {
        $informado = $this->option('amostra');

        return $informado === null || $informado === ''
            ? $padrao
            : max(1, (int) $informado);
    }
}
