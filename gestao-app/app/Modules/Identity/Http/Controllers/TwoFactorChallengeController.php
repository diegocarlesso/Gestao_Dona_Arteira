<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Services\PendingTwoFactorChallenge;
use App\Modules\Identity\Services\RecordSecurityEvent;
use App\Modules\Identity\Services\RememberedDeviceService;
use App\Modules\Identity\Services\VerifyTwoFactorChallengeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * O segundo fator no login (ADR-0021).
 *
 * Ninguém aqui está autenticado: quem chega acertou a senha e nada mais.
 * A identidade vem do `PendingTwoFactorChallenge`, não de `Auth::user()`.
 */
class TwoFactorChallengeController extends Controller
{
    /**
     * Os mesmos 5/minuto da senha (`LoginRequest`), agora por conta+IP.
     * Um código de seis dígitos tem um milhão de combinações: sem limite,
     * força bruta vira questão de minutos.
     */
    private const TENTATIVAS = 5;

    public function __construct(
        private readonly PendingTwoFactorChallenge $desafio,
        private readonly VerifyTwoFactorChallengeService $verificador,
        private readonly RememberedDeviceService $dispositivos,
        private readonly RecordSecurityEvent $trilha,
    ) {}

    public function create(): Response|RedirectResponse
    {
        if ($this->desafio->usuario() === null) {
            return $this->voltarAoLogin('A confirmação expirou. Entre novamente.');
        }

        return Inertia::render('identity/two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $usuario = $this->desafio->usuario();

        if ($usuario === null) {
            return $this->voltarAoLogin('A confirmação expirou. Entre novamente.');
        }

        $chave = 'desafio-2fa|'.$usuario->getKey().'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($chave, self::TENTATIVAS)) {
            // Derruba o desafio pendente em vez de só recusar a tentativa:
            // deixar o limbo aberto permitiria continuar martelando depois
            // que o limite passasse, sem precisar da senha de novo.
            $this->desafio->encerrar();

            $segundos = RateLimiter::availableIn($chave);

            return $this->voltarAoLogin(__('auth.throttle', [
                'seconds' => $segundos,
                'minutes' => ceil($segundos / 60),
            ]));
        }

        $request->validate([
            'codigo' => ['required', 'string'],
            'lembrar_dispositivo' => ['boolean'],
        ], [], ['codigo' => 'código']);

        // Aplicativos mostram o código em dois blocos ("123 456") e quem
        // digita copia o espaço junto. Recusar por isso seria recusar o
        // código certo.
        $codigo = str_replace(' ', '', trim((string) $request->string('codigo')));

        $porTotp = $this->verificador->porTotp($usuario, $codigo);
        $porRecuperacao = ! $porTotp && $this->verificador->porCodigoDeRecuperacao($usuario, $codigo);

        if (! $porTotp && ! $porRecuperacao) {
            RateLimiter::hit($chave);

            // Falha no segundo fator também é entrada recusada — e é a
            // mais interessante das duas: significa que alguém já tem a
            // senha certa desta conta.
            $this->trilha->record(
                SecurityEventType::LoginFailed,
                sobre: $usuario,
                identifier: $usuario->email,
                context: ['etapa' => 'dois_fatores'],
                ator: null,
            );

            throw ValidationException::withMessages([
                'codigo' => 'Código inválido.',
            ]);
        }

        RateLimiter::clear($chave);

        $lembrarSessao = $this->desafio->lembrarSessao();
        $this->desafio->encerrar();

        Auth::login($usuario, $lembrarSessao);
        $request->session()->regenerate();

        $resposta = redirect()->intended(route('dashboard', absolute: false));

        // **Só TOTP confia no dispositivo.** Um código de recuperação prova
        // posse da lista impressa, não do aplicativo — e é justamente o
        // caminho de "perdi o celular". Conceder 30 dias sem desafio nesse
        // momento seria premiar exatamente a situação mais suspeita.
        if ($porTotp && $request->boolean('lembrar_dispositivo')) {
            $resposta->withCookie($this->dispositivos->lembrar($usuario, $request));
        }

        return $resposta;
    }

    private function voltarAoLogin(string $mensagem): RedirectResponse
    {
        $this->desafio->encerrar();

        return to_route('login')->withErrors(['email' => $mensagem]);
    }
}
