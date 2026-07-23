<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Troca de senha obrigatória no primeiro acesso (pasta 18 §5).
 *
 * O `AdminInicialSeeder` cria a conta com uma senha provisória que
 * aparece no console do deploy — ou seja, uma senha que mais de uma
 * pessoa pode ter visto. Sem este middleware a flag
 * `must_change_password` seria só uma coluna no banco: escrita por quem
 * cria a conta, lida por ninguém.
 */
class EnsurePasswordIsChanged
{
    /**
     * Rotas que continuam alcançáveis com a senha provisória — sem elas
     * o usuário ficaria preso num laço de redirecionamento, ou sem
     * conseguir nem sair do sistema.
     *
     * @var list<string>
     */
    private const LIBERADAS = [
        'identity.password.forced.edit',
        'identity.password.forced.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario instanceof User || ! $usuario->must_change_password) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::LIBERADAS, strict: true)) {
            return $next($request);
        }

        return redirect()->route('identity.password.forced.edit');
    }
}
