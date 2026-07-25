<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Rules\PasswordPolicy;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    /**
     * Show the password reset page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            // `PasswordPolicy`, não `Rules\Password::defaults()`. Este era
            // o TERCEIRO caminho de definição de senha, e continuava
            // aceitando 8 caracteres sem checagem de vazamento depois que
            // os outros dois foram unificados — inclusive para quem acaba
            // de ser convidado e define a primeira senha justamente aqui.
            'password' => PasswordPolicy::validationRules(),
        ], [], ['password' => 'nova senha']);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                    // A troca obrigatória já preenchia isto; o reset não,
                    // e a coluna ficava mentindo que a senha nunca mudou.
                    'password_changed_at' => now(),
                    // Quem redefine a senha acabou de escolher uma sua —
                    // não faz sentido exigir troca no primeiro acesso.
                    'must_change_password' => false,
                ])->save();

                event(new PasswordReset($user));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        if ($status == Password::PasswordReset) {
            return to_route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }
}
