<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integrations\WooCommerce\Jobs\ProcessWooOrder;
use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Integrations\WooCommerce\Models\WooReconciliationFinding;
use App\Modules\Integrations\WooCommerce\Models\WooReconciliationRun;
use App\Modules\Integrations\WooCommerce\Models\WooWebhookEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel de integrações — o que a sync com o Woo está fazendo (docs/16 §5).
 *
 * Monitor operacional, não KPI de negócio: por isso não passa pelo
 * glossário da pasta 21. Uma render agregada (resumo + lista) para o
 * operador ver as pendências que a sync sinaliza — item não mapeado, sem
 * saldo, conflito, assinatura recusada — e reprocessar o que dá.
 *
 * O painel só **mostra** o estado gravado; corrigir a causa (mapear o
 * produto, repor o saldo) é em outra tela, e aí o reprocessar re-roda.
 */
class PanelController extends Controller
{
    /** As situações que viram card de alerta, na ordem de urgência. */
    private const ALERTAS = ['falha', 'pendencia', 'rejeitado', 'na_fila'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', WooWebhookEvent::class);

        $resumo = [];
        foreach (self::ALERTAS as $situacao) {
            $resumo[$situacao] = WooWebhookEvent::query()->situacao($situacao)->count();
        }

        $filtro = $request->string('situacao')->trim()->value();
        $filtroValido = in_array($filtro, self::ALERTAS, true);

        $eventos = WooWebhookEvent::query()
            ->when($filtroValido, fn ($q) => $q->situacao($filtro))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('integrations/panel', [
            'resumo' => $resumo,
            'eventos' => $eventos->through(fn (WooWebhookEvent $e): array => [
                'id' => $e->id,
                'topic' => $e->topic,
                'remote_id' => $e->remote_id,
                'situacao' => $e->situacao(),
                'erro' => $e->error,
                'received_at' => $e->received_at->toIso8601String(),
                'processed_at' => $e->processed_at?->toIso8601String(),
                'reprocessavel' => $e->reprocessavel() && ($request->user()?->can('reprocess', $e) ?? false),
            ]),
            'filtro' => $filtroValido ? $filtro : 'todos',
            'achados' => $this->achados($request),
            'ultimaReconciliacao' => $this->ultimaReconciliacao(),
        ]);
    }

    /**
     * As divergências de valor abertas (ADR-0025) — a seção de reconciliação
     * do painel. Distinta dos eventos: um achado tem ciclo próprio
     * (detectado→resolvido) e recorrência.
     *
     * @return array{abertos: int, recorrentes: int, lista: list<array<string, mixed>>}
     */
    private function achados(Request $request): array
    {
        $usuario = $request->user();

        return [
            'abertos' => WooReconciliationFinding::query()->abertas()->count(),
            'recorrentes' => WooReconciliationFinding::query()->abertas()->where('occurrences', '>', 1)->count(),
            'lista' => WooReconciliationFinding::query()
                ->abertas()
                ->orderByDesc('occurrences')
                ->orderByDesc('last_detected_at')
                ->limit(50)
                ->get()
                ->map(fn (WooReconciliationFinding $f): array => [
                    'id' => $f->id,
                    'remote_id' => $f->remote_id,
                    'detail' => $f->detail,
                    'occurrences' => $f->occurrences,
                    'recorrente' => $f->recorrente(),
                    'last_detected_at' => $f->last_detected_at->toIso8601String(),
                    'resolvivel' => $usuario?->can('resolve', $f) ?? false,
                ])
                ->all(),
        ];
    }

    /**
     * Resumo da última rodada + o heartbeat: se passou de ~26h, a rede de
     * segurança parou (cron silencioso, P-15). Nula = nunca rodou.
     *
     * @return array<string, mixed>|null
     */
    private function ultimaReconciliacao(): ?array
    {
        $run = WooReconciliationRun::ultima();

        if ($run === null) {
            return null;
        }

        return [
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'situacao' => $run->situacao(),
            'atrasada' => $run->atrasada(),
            'orders_checked' => $run->orders_checked,
            'orders_missing_imported' => $run->orders_missing_imported,
            'orders_divergent' => $run->orders_divergent,
            'findings_resolved' => $run->findings_resolved,
        ];
    }

    public function reprocessar(WooWebhookEvent $event): RedirectResponse
    {
        $this->authorize('reprocess', $event);

        // Limpa o carimbo e re-enfileira o mesmo bruto. Idempotente: pedido
        // já importado volta 'duplicado' no ImportWooOrder; o que muda é que
        // agora o produto mapeado/saldo reposto deixa a segunda passada
        // concluir.
        $event->forceFill(['processed_at' => null, 'error' => null])->save();

        ProcessWooOrder::dispatch($event->id);

        return back()->with('sucesso', "Evento #{$event->id} reenfileirado para reprocessar.");
    }

    /**
     * Resolve uma divergência de valor: a operação aceita o novo total do
     * site como linha de base (ADR-0025).
     *
     * Re-baseliza a âncora (`integration_mappings.checksum`) para o valor
     * corrente, para o achado não reabrir toda noite — **sem** mexer no
     * pedido do ERP, que continua congelado (BR-304). Quem discorda do total
     * novo corrige no site: ao voltar ao valor congelado, a reconciliação
     * auto-resolve sozinha, sem passar por aqui.
     */
    public function resolver(Request $request, WooReconciliationFinding $finding): RedirectResponse
    {
        $this->authorize('resolve', $finding);

        if ($finding->resolved_at !== null) {
            return back()->with('sucesso', "Divergência do pedido #{$finding->remote_id} já estava resolvida.");
        }

        $userId = (int) $request->user()?->id;
        $remote = $finding->remote_checksum;

        DB::transaction(function () use ($finding, $userId, $remote): void {
            $finding->resolverManual(
                $userId,
                'Aceito no painel — novo total do site adotado como linha de base (o pedido do ERP não muda, BR-304).',
            );

            if ($remote !== null) {
                IntegrationMapping::rebaselinar($finding->entity_type, $finding->remote_id, $remote);
            }
        });

        return back()->with('sucesso', "Divergência do pedido #{$finding->remote_id} resolvida.");
    }
}
