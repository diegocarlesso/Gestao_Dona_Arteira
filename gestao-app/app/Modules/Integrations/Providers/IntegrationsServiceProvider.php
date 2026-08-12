<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Providers;

use App\Modules\Finance\Contracts\CobrancaGatewayInterface;
use App\Modules\Integrations\Email\Listeners\EnviarEmailPedidoConfirmado;
use App\Modules\Integrations\Email\Listeners\EnviarEmailPedidoEnviado;
use App\Modules\Integrations\MercadoPago\Adapters\MercadoPagoGateway;
use App\Modules\Integrations\WooCommerce\Console\ApproveWooCommand;
use App\Modules\Integrations\WooCommerce\Console\ExtractWooCommand;
use App\Modules\Integrations\WooCommerce\Console\LoadWooCommand;
use App\Modules\Integrations\WooCommerce\Console\PullWooOrdersCommand;
use App\Modules\Integrations\WooCommerce\Console\TriageWooCommand;
use App\Modules\Integrations\WooCommerce\Console\ValidateWooCommand;
use App\Modules\Integrations\WooCommerce\Listeners\CancelarNoWoo;
use App\Modules\Integrations\WooCommerce\Listeners\EnviarExpedicaoAoWoo;
use App\Modules\Integrations\WooCommerce\Models\WooWebhookEvent;
use App\Modules\Integrations\WooCommerce\Policies\WooWebhookEventPolicy;
use App\Modules\Sales\Events\OrderCancelled;
use App\Modules\Sales\Events\OrderConfirmed;
use App\Modules\Sales\Events\OrderShipped;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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

        // Painel operacional (autenticado), docs/16 §5.
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        // Sobrescreve o `NullCobrancaGateway` (bind padrão do
        // `FinanceServiceProvider`, registrado antes deste — ordem de
        // `bootstrap/providers.php`) só quando a credencial de produção
        // existir. Mesmo espírito do `NFE_ENABLED` do Fiscal: desligado,
        // o Financeiro nunca finge que emitiu uma cobrança real (ADR-0018).
        if (config('integrations.mercadopago.enabled')) {
            $this->app->bind(CobrancaGatewayInterface::class, MercadoPagoGateway::class);
        }

        Gate::policy(WooWebhookEvent::class, WooWebhookEventPolicy::class);

        // Saída ERP→Woo: expedição devolve status + rastreio; cancelamento
        // devolve `cancelled` (sync-pedidos §7). Registrado aqui, no módulo
        // que reage — Vendas não sabe que o Woo escuta (ADR-0020).
        Event::listen(OrderShipped::class, EnviarExpedicaoAoWoo::class);
        Event::listen(OrderCancelled::class, CancelarNoWoo::class);

        // E-mail transacional (docs/15, BR-310): confirmação e rastreio.
        // Mesmo princípio — Vendas não sabe que existe e-mail.
        Event::listen(OrderConfirmed::class, EnviarEmailPedidoConfirmado::class);
        Event::listen(OrderShipped::class, EnviarEmailPedidoEnviado::class);

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
