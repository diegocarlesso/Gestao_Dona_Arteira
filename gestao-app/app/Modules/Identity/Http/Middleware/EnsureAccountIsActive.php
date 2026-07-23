<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Só conta ativa opera (docs/18-Usuarios/README.md §3).
 *
 * Fica no middleware, e não na validação do login, de propósito:
 * suspender alguém precisa **derrubar a sessão que já está aberta**. Uma
 * checagem apenas no login deixaria a pessoa demitida trabalhando até o
 * cookie expirar — que é justamente o cenário que a suspensão existe
 * para impedir.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario instanceof User && ! $usuario->canAuthenticate()) {
            $status = $usuario->status;

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => match ($status) {
                        UserStatus::Suspended => 'Esta conta está suspensa. Procure o administrador do sistema.',
                        UserStatus::Disabled => 'Esta conta foi desativada.',
                        default => 'Esta conta ainda não foi ativada. Verifique o convite enviado por e-mail.',
                    },
                ]);
        }

        return $next($request);
    }
}
