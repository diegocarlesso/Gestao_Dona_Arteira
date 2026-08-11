<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Console;

use App\Modules\Integrations\WooCommerce\Client;
use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Integrations\WooCommerce\Models\WooWebhookEvent;
use App\Modules\Integrations\WooCommerce\Services\ImportWooOrder;
use App\Modules\Integrations\WooCommerce\Services\ResultadoDaImportacao;
use Illuminate\Console\Command;
use Throwable;

/**
 * Puxa pedidos do WooCommerce para o ERP — docs/16 §4/§5.
 *
 * O outro gatilho da entrada, ao lado do webhook (ADR-0007): a puxada é a
 * rede de segurança que pega o que o webhook perdeu (site fora do ar,
 * deploy) e o histórico que nunca disparou webhook. Filtra por
 * `modified_after` para trazer só o que mudou desde a última passada.
 *
 * O miolo é o mesmo `ImportWooOrder` do webhook — aqui só se busca e se
 * alimenta. Pedido já importado é pulado sem custo (o mapeamento arbitra),
 * então rodar de novo é seguro.
 */
class PullWooOrdersCommand extends Command
{
    protected $signature = 'erp:woo:pull-orders
        {--after= : só pedidos modificados depois desta data ISO (ex.: 2026-07-28T00:00:00)}
        {--status=processing,on-hold : status do Woo a trazer, separados por vírgula}
        {--historico : puxada de histórico (docs/16 §4) — "completed" entra direto como Entregue, sem reserva}
        {--dry-run : conta o que viria, sem gravar}';

    protected $description = 'Puxa pedidos do WooCommerce e cria os pedidos correspondentes no ERP';

    public function handle(Client $client, ImportWooOrder $import): int
    {
        if (! $this->conferirConexao($client)) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $historico = (bool) $this->option('historico');

        $parametros = [
            'status' => (string) $this->option('status'),
            'orderby' => 'modified',
            'order' => 'asc',
        ];

        if (is_string($this->option('after')) && $this->option('after') !== '') {
            $parametros['modified_after'] = (string) $this->option('after');
        }

        $contagem = [
            'vistos' => 0, 'importados' => 0, 'pendentes' => 0, 'duplicados' => 0,
            'cancelados' => 0, 'conflitos' => 0, 'ignorados' => 0, 'erros' => 0,
            'com_endereco' => 0, 'com_nota' => 0, 'com_forma_entrega' => 0,
        ];

        try {
            foreach ($client->paginar('orders', $parametros) as $pagina) {
                foreach ($pagina['itens'] as $order) {
                    $contagem['vistos']++;

                    if ($dryRun) {
                        continue;
                    }

                    $this->importarUm($import, $order, $historico, $contagem);
                }
            }
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->relatorio($contagem, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, int>  $contagem
     */
    private function importarUm(ImportWooOrder $import, array $order, bool $historico, array &$contagem): void
    {
        $wooId = (string) ($order['id'] ?? '');
        $status = (string) ($order['status'] ?? '');

        // Já importado: pula antes de gravar bruto, para o re-pull não
        // encher a tabela de eventos com repetição — **exceto** se o site o
        // cancelou, que é justamente a atualização que a reconciliação
        // precisa pegar se o webhook se perdeu (sync-pedidos §5).
        $jaImportado = $wooId !== '' && IntegrationMapping::localDe('order', $wooId) !== null;

        if ($jaImportado && ! in_array($status, ImportWooOrder::CANCELAMENTO, true)) {
            $contagem['duplicados']++;

            return;
        }

        $evento = WooWebhookEvent::query()->create([
            'topic' => 'order.pulled',
            'remote_id' => $wooId !== '' ? $wooId : null,
            'signature_valid' => true, // a puxada é autenticada pela chave REST, não por HMAC
            'payload' => $order,
            'received_at' => now(),
        ]);

        try {
            $resultado = $import->handle($order, historico: $historico);
            $evento->marcarProcessado($resultado->motivo);
            $this->tally($resultado, $contagem);

            if ($resultado->processado()) {
                $this->tallyCampos($order, $contagem);
            }
        } catch (Throwable $e) {
            $evento->registrarErro($e->getMessage());
            $contagem['erros']++;
            $this->components->warn("Pedido Woo #{$wooId}: {$e->getMessage()}");
        }
    }

    /**
     * Conta quantos pedidos importados trouxeram endereço, nota do cliente
     * ou forma de entrega (BR-707) — só para o relatório, não afeta a
     * importação em si (o `ImportWooOrder` já capturou tudo).
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, int>  $contagem
     */
    private function tallyCampos(array $order, array &$contagem): void
    {
        $temEndereco = false;

        foreach (['billing', 'shipping'] as $bloco) {
            $dados = (array) ($order[$bloco] ?? []);

            if (trim((string) ($dados['address_1'] ?? '')) !== '') {
                $temEndereco = true;

                break;
            }
        }

        if ($temEndereco) {
            $contagem['com_endereco']++;
        }

        if (trim((string) ($order['customer_note'] ?? '')) !== '') {
            $contagem['com_nota']++;
        }

        $linhas = (array) ($order['shipping_lines'] ?? []);
        $primeira = $linhas[0] ?? null;

        if (is_array($primeira) && trim((string) ($primeira['method_title'] ?? '')) !== '') {
            $contagem['com_forma_entrega']++;
        }
    }

    /**
     * @param  array<string, int>  $contagem
     */
    private function tally(ResultadoDaImportacao $resultado, array &$contagem): void
    {
        match ($resultado->status) {
            ResultadoDaImportacao::IMPORTADO => $contagem['importados']++,
            ResultadoDaImportacao::PENDENTE => $contagem['pendentes']++,
            ResultadoDaImportacao::DUPLICADO => $contagem['duplicados']++,
            ResultadoDaImportacao::SEM_ITENS => $contagem['pendentes']++,
            ResultadoDaImportacao::CANCELADO => $contagem['cancelados']++,
            ResultadoDaImportacao::CONFLITO => $contagem['conflitos']++,
            default => $contagem['ignorados']++,
        };
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

        return true;
    }

    /**
     * @param  array<string, int>  $contagem
     */
    private function relatorio(array $contagem, bool $dryRun): void
    {
        $this->newLine();

        if ($dryRun) {
            $this->components->info("Dry-run: {$contagem['vistos']} pedidos viriam. Nada gravado.");

            return;
        }

        $this->components->twoColumnDetail('<fg=gray>Pedidos vistos</>', (string) $contagem['vistos']);
        $this->components->twoColumnDetail('Importados (confirmados)', (string) $contagem['importados']);
        $this->components->twoColumnDetail('<fg=yellow>Pendentes (rascunho)</>', (string) $contagem['pendentes']);
        $this->components->twoColumnDetail('Já existentes', (string) $contagem['duplicados']);
        $this->components->twoColumnDetail('Cancelados (do site)', (string) $contagem['cancelados']);
        $this->components->twoColumnDetail('Ignorados (status)', (string) $contagem['ignorados']);

        if ($contagem['conflitos'] > 0) {
            $this->components->twoColumnDetail('<fg=yellow>Conflitos (cancelado no site, já expedido)</>', (string) $contagem['conflitos']);
        }

        if ($contagem['erros'] > 0) {
            $this->components->twoColumnDetail('<fg=red>Erros</>', (string) $contagem['erros']);
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Com endereço capturado</>', (string) $contagem['com_endereco']);
        $this->components->twoColumnDetail('<fg=gray>Com nota do cliente</>', (string) $contagem['com_nota']);
        $this->components->twoColumnDetail('<fg=gray>Com forma de entrega</>', (string) $contagem['com_forma_entrega']);
    }
}
