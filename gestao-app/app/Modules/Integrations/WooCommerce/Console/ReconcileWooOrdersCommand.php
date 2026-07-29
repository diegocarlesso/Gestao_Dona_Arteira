<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Console;

use App\Modules\Integrations\WooCommerce\Client;
use App\Modules\Integrations\WooCommerce\Models\WooReconciliationRun;
use App\Modules\Integrations\WooCommerce\Services\ReconcileWooOrders;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconcilia os pedidos do site com o ERP — ADR-0025, docs/16 §5.
 *
 * O gatilho agendado (madrugada) da rede de segurança. Roda inerte enquanto a
 * integração está desligada — nada de rodada nem de chamada à API sem o Woo
 * ligado, para não sujar o heartbeat antes do cutover.
 *
 * Chamado por `Schedule::call()` (nunca `Schedule::command()`: `proc_open`
 * está bloqueado no Business — P-15).
 */
class ReconcileWooOrdersCommand extends Command
{
    protected $signature = 'erp:woo:reconcile-orders
        {--days=7 : janela de pedidos a reconciliar, em dias}';

    protected $description = 'Reconcilia os pedidos do site dos últimos N dias com o ERP (docs/16 §5)';

    public function handle(Client $client, ReconcileWooOrders $reconcile): int
    {
        if (! $client->isEnabled()) {
            $this->components->info('Integração WooCommerce desligada — nada a reconciliar.');

            return self::SUCCESS;
        }

        $dias = max(1, (int) $this->option('days'));

        try {
            $run = $reconcile->handle($dias);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->relatorio($run);

        return self::SUCCESS;
    }

    private function relatorio(WooReconciliationRun $run): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Pedidos conferidos</>', (string) $run->orders_checked);
        $this->components->twoColumnDetail('Importados (ausentes no ERP)', (string) $run->orders_missing_imported);
        $this->components->twoColumnDetail('Resolvidos (convergiram)', (string) $run->findings_resolved);

        if ($run->orders_divergent > 0) {
            $this->components->twoColumnDetail('<fg=yellow>Divergências de valor</>', (string) $run->orders_divergent);
        }
    }
}
