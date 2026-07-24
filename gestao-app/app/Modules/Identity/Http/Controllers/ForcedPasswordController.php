<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Events\PasswordChanged;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Rules\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Troca obrigatória de senha no primeiro acesso (pasta 18 §5).
 *
 * Separado do `Settings\PasswordController` de propósito: aqui a pessoa
 * está presa nesta tela pelo middleware, o layout não tem menu (não há
 * para onde navegar) e a senha atual é a provisória, que pode ter sido
 * vista por outras pessoas no console do deploy.
 */
class ForcedPasswordController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('identity/forced-password');
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $validado = $request->validate([
            'current_password' => ['required', 'current_password'],
            // A política vive em PasswordPolicy, não aqui: ela valia neste
            // caminho e não valia no de configurações, e regra duplicada
            // é regra que diverge.
            'password' => PasswordPolicy::validationRules(),
        ], [], [
            'current_password' => 'senha atual',
            'password' => 'nova senha',
        ]);

        $usuario->forceFill([
            'password' => Hash::make($validado['password']),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        PasswordChanged::dispatch($usuario, obrigatoria: true);

        return to_route('dashboard')->with('sucesso', 'Senha alterada. Bem-vindo(a) ao ERP.');
    }
}
