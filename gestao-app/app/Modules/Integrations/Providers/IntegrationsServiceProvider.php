<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Providers;

use App\Modules\Integrations\WooCommerce\Console\ApproveWooCommand;
use App\Modules\Integrations\WooCommerce\Console\ExtractWooCommand;
use App\Modules\Integrations\WooCommerce\Console\LoadWooCommand;
use App\Modules\Integrations\WooCommerce\Console\PullWooOrdersCommand;
use App\Modules\Integrations\WooCommerce\Console\TriageWooCommand;
use App\Modules\Integrations\WooCommerce\Console\ValidateWooCommand;
use App\Modules\Integrations\WooCommerce\Listeners\CancelarNoWoo;
use App\Modules\Integrations\WooCommerce\Listeners\EnviarExpedicaoAoWoo;
use App\Modules\Sales\Events\OrderCancelled;
use App\Modules\Sales\Events\OrderShipped;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Provider do módulo Integrações — docs/15-Integracoes.
 *
 * A camada anticorrupção do projeto: é aqui que sistema externo encosta,
 * e em lugar nenhum além daqui (BR-701, princípio 2 da pasta 15). Os
 * módulos de domínio não conhecem formato externo.
 */
class IntegrationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Webhooks entram por aqui (docs/16 §4). Fora do grupo `web`: sem
        // CSRF nem sessão, autenticados pela assinatura HMAC (BR-701).
        $this->loadRoutesFrom(__DIR__.'/../Routes/webhooks.php');

        // Saída ERP→Woo: expedição devolve status + rastreio; cancelamento
        // devolve `cancelled` (sync-pedidos §7). Registrado aqui, no módulo
        // que reage — Vendas não sabe que o Woo escuta (ADR-0020).
        Event::listen(OrderShipped::class, EnviarExpedicaoAoWoo::class);
        Event::listen(OrderCancelled::class, CancelarNoWoo::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExtractWooCommand::class,
                TriageWooCommand::class,
                ApproveWooCommand::class,
                LoadWooCommand::class,
                ValidateWooCommand::class,
                PullWooOrdersCommand::class,
            ]);
        }
    }
}
