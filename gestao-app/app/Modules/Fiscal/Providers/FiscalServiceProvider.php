<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Providers;

use App\Modules\Fiscal\Contracts\NfeGatewayInterface;
use App\Modules\Fiscal\Listeners\EmitirNfeAoConfirmarPedido;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Policies\InvoicePolicy;
use App\Modules\Fiscal\Services\Gateways\NullNfeGateway;
use App\Modules\Sales\Events\OrderConfirmed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Provider do módulo Fiscal — docs/13 + docs/14, ADR-0025.
 *
 * Um módulo só para perfis fiscais **e** emissão: a emissão sempre resolve
 * um perfil antes de montar o XML, e dois módulos-bebê seria exatamente o
 * cenário que o ADR-0020 manda consolidar.
 */
class FiscalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ⚠️ **Este é o bind que muda quando a emissão real entrar.**
        //
        // `NullNfeGateway` registra e numera a nota mas não transmite nada —
        // devolve `pending` com o motivo. `SpedNfeGateway::class` **já
        // existe** e monta, assina e transmite; o que ele ainda não teve é
        // uma nota autorizada de verdade do outro lado.
        //
        // Feito (2026-08-10):
        //   1. `nfephp-org/sped-nfe` instalado (^5.2);
        //   2. pré-flight das extensões no Hostinger, verificado
        //      *executando* nos dois SAPIs (docs/14 §8) — o painel da
        //      hospedagem aceita configuração sem aplicá-la;
        //   3. montagem do XML coberta por teste contra o XSD oficial, e
        //      assinatura exercitada com certificado autoassinado.
        //
        // Falta, e é por isso que o bind continua no Null:
        //   1. certificado A1 real, fora do webroot, cifrado (pasta 25);
        //   2. `NFE_EMITENTE_*`, `NFE_PIS_CST` e `NFE_COFINS_CST` — vazios
        //      até a doc 13 sair de bloqueada no contador;
        //   3. código IBGE do município no cadastro de endereços de Vendas
        //      (`enderDest/cMun` é obrigatório e ninguém o guarda hoje);
        //   4. a bateria em homologação que a BR-605 exige.
        //
        // Só esta linha muda de lugar. O domínio — job, listener, montagem,
        // numeração, eventos — continua igual, que é o motivo de a interface
        // existir (ADR-0009/ADR-0015).
        $this->app->bind(NfeGatewayInterface::class, NullNfeGateway::class);
    }

    public function boot(): void
    {
        // O módulo que **ouve** registra o listener, não o que emite: Vendas
        // não sabe que o Fiscal existe (ADR-0020). Mesmo arranjo que
        // `IntegrationsServiceProvider` já usa para `OrderShipped`.
        Event::listen(OrderConfirmed::class, EmitirNfeAoConfirmarPedido::class);

        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        Gate::policy(Invoice::class, InvoicePolicy::class);
    }
}
