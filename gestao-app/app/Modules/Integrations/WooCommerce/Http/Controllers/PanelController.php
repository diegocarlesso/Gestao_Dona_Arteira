<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integrations\WooCommerce\Jobs\ProcessWooOrder;
use App\Modules\Integrations\WooCommerce\Models\WooWebhookEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        ]);
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
}
