<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA obrigatório para `admin` e `finance` (BR-804, ADR-0021).
 *
 * Sem prazo de carência, deliberadamente: um prazo é o mecanismo clássico
 * pelo qual "2FA obrigatório" nunca chega a ser ativado por ninguém. O
 * precedente em produção (`senha.trocada`) também não tem, e com uma ou
 * duas contas afetadas a contagem regressiva custaria mais do que entrega.
 *
 * Não é um lockout: a tela de configuração fica sempre alcançável, e é
 * para ela que este middleware empurra. Quem cai aqui está a um QR code de
 * distância de voltar a trabalhar.
 */
class EnsureTwoFactorIsConfirmed
{
    /**
     * Rotas alcançáveis sem o 2FA configurado — sem elas a pessoa ficaria
     * num laço de redirecionamento, ou sem conseguir nem sair do sistema.
     *
     * @var list<string>
     */
    private const LIBERADAS = [
        'identity.2fa.edit',
        'identity.2fa.store',
        'identity.2fa.confirm',
        'identity.2fa.destroy',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario instanceof User) {
            return $next($request);
        }

        // Quem não deve 2FA passa; quem deve e já confirmou, também. A
        // pergunta é sobre o papel, não sobre preferência: perder o papel
        // `finance` deixa de obrigar, sem que ninguém tenha de desfazer
        // nada.
        if (! $usuario->requiresTwoFactor() || $usuario->hasTwoFactorEnabled()) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::LIBERADAS, strict: true)) {
            return $next($request);
        }

        return redirect()->route('identity.2fa.edit');
    }
}
