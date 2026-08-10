<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Providers;

use App\Modules\Fiscal\Contracts\NfeGatewayInterface;
use App\Modules\Fiscal\Listeners\EmitirNfeAoConfirmarPedido;
use App\Modules\Fiscal\Services\Gateways\NullNfeGateway;
use App\Modules\Sales\Events\OrderConfirmed;
use Illuminate\Support\Facades\Event;
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
        // devolve `pending` com o motivo. Trocar por `SpedNfeGateway::class`
        // é o próximo incremento, e depende de três coisas que **não** foram
        // feitas nesta passada:
        //
        //   1. `composer require nfephp-org/sped-nfe`;
        //   2. pré-flight das extensões PHP no Hostinger (openssl, soap,
        //      curl, dom — docs/14 §6), verificado *executando* no servidor:
        //      o painel da hospedagem aceita configuração sem aplicá-la;
        //   3. certificado A1 de teste instalado fora do webroot, cifrado
        //      (pasta 25), com `NFE_CERT_PATH`/`NFE_CERT_PASSWORD`.
        //
        // Só isto muda de lugar. O domínio — job, listener, montagem,
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
    }
}
