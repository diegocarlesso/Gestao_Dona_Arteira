<?php

declare(strict_types=1);

namespace App\Modules\Integrations\MercadoPago\Jobs;

use App\Modules\Finance\Services\ListarCobrancasAbertasQuery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Consulta o Mercado Pago sobre toda cobrança ainda aberta — a garantia
 * contra webhook perdido (docs/12-Financeiro/01-cobranca-e-boletos.md §6:
 * "a consulta é a garantia, o webhook é otimização"). Agendada de hora em
 * hora em `routes/console.php`.
 *
 * Reaproveita o mesmo job do webhook (`ProcessarWebhookMercadoPago`): o
 * efeito é idêntico — buscar o estado real e repassar ao Financeiro — só
 * a origem do disparo muda. Uma cobrança com falha isolada não trava as
 * outras: cada uma vira um job próprio, com seu próprio retry.
 */
class ReconciliarCobrancasMercadoPago implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(ListarCobrancasAbertasQuery $query): void
    {
        foreach ($query->handle() as $cobranca) {
            ProcessarWebhookMercadoPago::dispatch($cobranca['provider_charge_id'], null);
        }
    }
}
