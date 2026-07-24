<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Modules\Identity\Services\PendingTwoFactorChallenge;
use App\Modules\Identity\Services\RememberedDeviceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     *
     * Duas saídas desde o ADR-0021: a sessão abre na hora para quem não
     * usa 2FA (ou está num dispositivo já confiado), e vai para o desafio
     * TOTP para quem usa.
     */
    public function store(
        LoginRequest $request,
        PendingTwoFactorChallenge $desafio,
        RememberedDeviceService $dispositivos,
    ): RedirectResponse {
        $usuario = $request->validarCredenciais();

        if ($usuario->hasTwoFactorEnabled() && ! $dispositivos->reconhece($request, $usuario)) {
            // Nada de `Auth::login()` aqui: a senha certa é metade da
            // credencial, e uma sessão aberta agora seria uma sessão
            // autenticada sem segundo fator — exatamente o que o BR-804
            // proíbe. O id fica no limbo da sessão por poucos minutos.
            $desafio->iniciar($usuario, $request->boolean('remember'));

            return to_route('identity.2fa.challenge');
        }

        Auth::login($usuario, $request->boolean('remember'));

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
