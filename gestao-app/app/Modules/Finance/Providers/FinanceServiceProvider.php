<?php

declare(strict_types=1);

namespace App\Modules\Finance\Providers;

use App\Modules\Finance\Contracts\CobrancaGatewayInterface;
use App\Modules\Finance\Listeners\RegistrarRecebivelAoConfirmarPedido;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Policies\FinanceSettingsPolicy;
use App\Modules\Finance\Policies\TitlePolicy;
use App\Modules\Finance\Services\Gateways\NullCobrancaGateway;
use App\Modules\Sales\Events\OrderConfirmed;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Provider do módulo Financeiro — docs/12, Gate 04.
 */
class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Padrão até as credenciais de produção do Mercado Pago existirem —
        // `IntegrationsServiceProvider` (registrado depois, `bootstrap/
        // providers.php`) sobrescreve este bind quando a integração está
        // ligada. Mesmo arranjo do `NfeGatewayInterface` no Fiscal
        // (ADR-0009/ADR-0018).
        $this->app->bind(CobrancaGatewayInterface::class, NullCobrancaGateway::class);
    }

    public function boot(): void
    {
        // `settleable_type` grava `'payable'`/`'receivable'`, não o nome
        // da classe cru — estável mesmo se `Payable`/`Receivable` forem
        // renomeados ou movidos um dia (mesmo motivo de todo morphMap).
        //
        // `morphMap()`, não `enforceMorphMap()`: a segunda é global e
        // passaria a exigir alias de **todo** model auditável do app (a
        // trilha de auditoria também é polimórfica) — quebraria módulos
        // que nunca pediram isso. `morphMap()` só resolve os dois nomes
        // que o Financeiro usa, sem mexer no resto.
        Relation::morphMap([
            'payable' => Payable::class,
            'receivable' => Receivable::class,
        ]);

        // Vendas não sabe que o Financeiro existe (ADR-0020) — quem ouve
        // registra o listener, mesmo arranjo do Fiscal para o mesmo Event.
        Event::listen(OrderConfirmed::class, RegistrarRecebivelAoConfirmarPedido::class);

        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        // Um TitlePolicy só, registrado duas vezes: a regra de quem vê/dá
        // baixa/estorna é idêntica para Payable e Receivable.
        Gate::policy(Payable::class, TitlePolicy::class);
        Gate::policy(Receivable::class, TitlePolicy::class);

        Gate::policy(FinanceAccount::class, FinanceSettingsPolicy::class);
        Gate::policy(FinanceCategory::class, FinanceSettingsPolicy::class);
    }
}
