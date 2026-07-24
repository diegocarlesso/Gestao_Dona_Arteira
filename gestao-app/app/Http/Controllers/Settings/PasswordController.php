<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Events\PasswordChanged;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Rules\PasswordPolicy;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Show the user's password settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/password', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's password.
     *
     * A política de senha vem de `PasswordPolicy` (pasta 18 §3). Este
     * caminho usava `Password::defaults()` — 8 caracteres, sem checagem
     * contra vazamentos — enquanto a troca obrigatória exigia 12 com
     * checagem. Quem trocasse a senha por aqui escapava da regra que a
     * documentação afirma valer.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => PasswordPolicy::validationRules(),
        ], [], [
            'current_password' => 'senha atual',
            'password' => 'nova senha',
        ]);

        /** @var User $usuario */
        $usuario = $request->user();

        // `password_changed_at` também passou a ser preenchido: a coluna
        // existe para responder "há quanto tempo esta senha é a mesma", e
        // ficava mentindo para quem trocasse por esta tela.
        $usuario->forceFill([
            'password' => Hash::make($validated['password']),
            'password_changed_at' => now(),
        ])->save();

        PasswordChanged::dispatch($usuario);

        return back();
    }
}
