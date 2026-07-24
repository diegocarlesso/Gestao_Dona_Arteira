<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Modules\Identity\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Confere e-mail e senha **sem abrir a sessão**, devolvendo quem é.
     *
     * Era um `Auth::attempt()`, que autenticava na hora. Deixou de ser
     * quando o 2FA entrou (ADR-0021): com segundo fator, a senha certa
     * ainda não é uma entrada — é metade dela. Quem promove a sessão é o
     * controller, depois do desafio, ou logo em seguida se a conta não
     * usa 2FA.
     *
     * Isso mantém a trilha da pasta 26 honesta: `login.ok` passa a
     * significar "entrou", não "acertou a senha". Se o `Login` fosse
     * disparado aqui, um desafio TOTP abandonado no meio ficaria
     * registrado como entrada bem-sucedida — e `last_login_at` também
     * mentiria.
     *
     * @throws ValidationException
     */
    public function validarCredenciais(): User
    {
        $this->ensureIsNotRateLimited();

        $credenciais = $this->only('email', 'password');
        $usuario = User::firstWhere('email', $this->string('email')->toString());

        if ($usuario === null || ! Auth::validate($credenciais)) {
            RateLimiter::hit($this->throttleKey());

            // `Auth::validate()` não dispara `Failed` — só o `attempt()`
            // disparava. Sem esta linha o canal `login.failed` da pasta 26
            // secaria em silêncio, que é o pior jeito de perder justamente
            // o evento que mostra ataque de força bruta.
            event(new Failed('web', $usuario, $credenciais));

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $usuario;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate($this->string('email')->lower().'|'.$this->ip());
    }
}
