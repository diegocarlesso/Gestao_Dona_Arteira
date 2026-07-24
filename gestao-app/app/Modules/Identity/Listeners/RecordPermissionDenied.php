<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RecordSecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

/**
 * Acesso negado por permissão → trilha de segurança (pasta 26 §2).
 *
 * **Por que não é um listener de evento:** o Laravel não emite evento
 * algum quando um Gate nega. A alternativa aparente seria `Gate::after`,
 * que roda em **toda** checagem — inclusive nas dezenas que a UI faz por
 * página só para decidir se mostra um botão. A trilha encheria de
 * negativas especulativas e o sinal desapareceria no ruído.
 *
 * O fato que interessa é outro: alguém **tentou fazer** algo e foi
 * recusado. Isso acontece quando a exceção chega ao handler, e é ali que
 * este registro é chamado — ver `bootstrap/app.php`.
 */
class RecordPermissionDenied
{
    public function __construct(private readonly RecordSecurityEvent $trilha) {}

    public function handle(Throwable $e, Request $request): void
    {
        if (! $e instanceof AccessDeniedHttpException && ! $e instanceof UnauthorizedException) {
            return;
        }

        $usuario = Auth::user();
        $usuario = $usuario instanceof User ? $usuario : null;

        $this->trilha->record(
            SecurityEventType::PermissionDenied,
            sobre: $usuario,
            context: [
                // A rota nomeada diz mais que a URL numa leitura futura,
                // mas a URL é o que existe quando a rota não tem nome.
                'rota' => $request->route()?->getName() ?? $request->path(),
                'metodo' => $request->method(),
                // A `AuthorizationException` original vive em
                // `getPrevious()` — o handler a embrulha antes de chegar
                // aqui — e é a mensagem dela que nomeia a habilidade
                // negada. A da UnauthorizedException nomeia a permissão
                // exigida pelo middleware do spatie.
                'motivo' => mb_substr(
                    $e->getPrevious()?->getMessage() ?: $e->getMessage(),
                    0,
                    500,
                ),
            ],
            ator: $usuario,
        );
    }
}
