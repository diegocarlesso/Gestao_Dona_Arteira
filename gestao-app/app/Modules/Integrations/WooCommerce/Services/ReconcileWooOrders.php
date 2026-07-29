<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

use App\Modules\Integrations\WooCommerce\Client;
use App\Modules\Integrations\WooCommerce\Enums\TipoDeDivergencia;
use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Integrations\WooCommerce\Models\WooReconciliationFinding;
use App\Modules\Integrations\WooCommerce\Models\WooReconciliationRun;
use App\Modules\Integrations\WooCommerce\Models\WooWebhookEvent;
use Throwable;

/**
 * Reconciliação diária de pedidos Woo→ERP — ADR-0025, docs/16 §5.
 *
 * A rede de segurança do framework de integração, ao lado do webhook e da
 * puxada (ADR-0007). Repassa os pedidos do site dos últimos N dias e trata
 * duas classes de divergência, sem nunca mutar o pedido do ERP nem cruzar a
 * fronteira do ADR-0020 (compara Woo-corrente × âncora em `integration_mappings`,
 * jamais lê `Sales\Order`):
 *
 * - **ausente** (webhook perdido): importa pelo mesmo miolo da puxada
 *   (`ImportWooOrder`) e deixa um `order.pulled` — a correção sai de graça;
 * - **valor divergente** (total mudou no Woo depois da importação — BR-702):
 *   vira achado para a operação investigar (BR-304 congela o pedido, então
 *   não há o que corrigir sozinho).
 *
 * Cancelamento de pedido já importado que o webhook perdeu é refletido pelo
 * miolo (sync-pedidos §5), não vira achado.
 */
class ReconcileWooOrders
{
    public function __construct(
        private readonly Client $client,
        private readonly ImportWooOrder $import,
    ) {}

    public function handle(int $windowDays = 7): WooReconciliationRun
    {
        $run = WooReconciliationRun::iniciar($windowDays);

        $counts = [
            'orders_checked' => 0,
            'orders_missing_imported' => 0,
            'orders_divergent' => 0,
            'findings_resolved' => 0,
        ];

        try {
            $desde = now()->subDays($windowDays)->toIso8601String();

            foreach ($this->client->paginar('orders', [
                'modified_after' => $desde,
                'orderby' => 'modified',
                'order' => 'asc',
            ]) as $pagina) {
                foreach ($pagina['itens'] as $order) {
                    $this->conferir($order, $counts);
                }
            }
        } catch (Throwable $e) {
            // A rodada fica marcada como falha (finished_at + error); o comando
            // relança para logar e sair com FAILURE.
            $run->falhar($e->getMessage());

            throw $e;
        }

        $run->concluir($counts);

        return $run;
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, int>  $counts
     */
    private function conferir(array $order, array &$counts): void
    {
        $wooId = (string) ($order['id'] ?? '');

        if ($wooId === '') {
            return;
        }

        $counts['orders_checked']++;

        $status = (string) ($order['status'] ?? '');
        $localId = IntegrationMapping::localDe('order', $wooId);

        if ($localId === null) {
            $this->importarAusente($order, $wooId, $counts);

            return;
        }

        // Cancelamento que o webhook pode ter perdido: reflete pelo miolo
        // (idempotente — já cancelado vira no-op). Não é achado.
        if (in_array($status, ImportWooOrder::CANCELAMENTO, true)) {
            $this->import->handle($order);

            return;
        }

        $this->conferirValor($order, $wooId, $localId, $counts);
    }

    /**
     * Pedido que o ERP não tem: importa pelo mesmo caminho da puxada.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, int>  $counts
     */
    private function importarAusente(array $order, string $wooId, array &$counts): void
    {
        $evento = WooWebhookEvent::query()->create([
            'topic' => 'order.pulled',
            'remote_id' => $wooId,
            'signature_valid' => true, // autenticado pela chave REST, não por HMAC
            'payload' => $order,
            'received_at' => now(),
        ]);

        try {
            $resultado = $this->import->handle($order);
            $evento->marcarProcessado($resultado->motivo);

            if ($resultado->processado()) {
                $counts['orders_missing_imported']++;
            }
        } catch (Throwable $e) {
            // Não derruba a rodada: o achado fica no painel de eventos como
            // falha, e a próxima passada tenta de novo.
            $evento->registrarErro($e->getMessage());
        }
    }

    /**
     * Pedido já importado: compara o total corrente do Woo com a âncora
     * congelada na importação.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, int>  $counts
     */
    private function conferirValor(array $order, string $wooId, int $localId, array &$counts): void
    {
        $ancora = IntegrationMapping::checksumDe('order', $wooId);
        $corrente = OrderChecksum::of($order);

        if ($ancora === null) {
            // Backfill: pedido importado antes deste recurso, sem âncora.
            // Adota o total corrente como linha de base — não há referência
            // congelada para acusar ninguém (ADR-0025).
            IntegrationMapping::rebaselinar('order', $wooId, $corrente);

            return;
        }

        if ($ancora === $corrente) {
            $counts['findings_resolved'] += WooReconciliationFinding::autoResolver('order', $wooId);

            return;
        }

        WooReconciliationFinding::registrar(
            entityType: 'order',
            remoteId: $wooId,
            localId: $localId,
            kind: TipoDeDivergencia::ChecksumMismatch,
            baseline: $ancora,
            remote: $corrente,
            detail: "Total no site mudou para R$ {$corrente} desde a importação (congelado: R$ {$ancora}) — possível edição no wp-admin (BR-702).",
        );

        $counts['orders_divergent']++;
    }
}
