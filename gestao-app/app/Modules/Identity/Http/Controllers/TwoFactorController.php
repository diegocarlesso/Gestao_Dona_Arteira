<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ConfirmTwoFactorService;
use App\Modules\Identity\Services\DisableTwoFactorService;
use App\Modules\Identity\Services\RegenerateRecoveryCodesService;
use App\Modules\Identity\Services\RememberedDeviceService;
use App\Modules\Identity\Services\StartTwoFactorSetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Fortify;

/**
 * Configuração do segundo fator pela própria pessoa (ADR-0021).
 *
 * Um admin não configura o 2FA de outra pessoa: o segredo tem de ser lido
 * do QR pelo aplicativo de quem vai usá-lo. O que um admin pode é
 * desativar o de alguém que perdeu o acesso — e isso é caso da tela de
 * usuários, com Policy própria, não daqui.
 */
class TwoFactorController extends Controller
{
    private const TENTATIVAS_DE_CONFIRMACAO = 5;

    public function edit(Request $request, RememberedDeviceService $dispositivos): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $confirmado = $usuario->hasTwoFactorEnabled();
        $emConfiguracao = ! $confirmado && $usuario->two_factor_secret !== null;

        return Inertia::render('identity/two-factor', [
            'confirmado' => $confirmado,
            'obrigatorio' => $usuario->requiresTwoFactor(),
            // O QR só existe entre "comecei" e "confirmei". Depois de
            // confirmado, mostrá-lo de novo seria reexibir o segredo a
            // quem passar pela tela.
            'qrCodeSvg' => $emConfiguracao ? $usuario->twoFactorQrCodeSvg() : null,
            'chaveManual' => $emConfiguracao ? $this->chaveManual($usuario) : null,
            // Os códigos em claro só aparecem uma vez, logo após gerar —
            // e vêm por flash, não por consulta, para que atualizar a
            // página não os traga de volta à tela.
            'codigosDeRecuperacao' => $request->session()->get('codigos_de_recuperacao'),
            'dispositivos' => $confirmado ? $this->dispositivosParaTela($usuario) : [],
        ]);
    }

    /**
     * Gera o segredo e abre o QR. Ainda não ativa nada.
     */
    public function store(Request $request, StartTwoFactorSetupService $iniciar): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $iniciar->executar($usuario);

        return to_route('identity.2fa.edit');
    }

    /**
     * Confirma com um código do aplicativo — a partir daqui o 2FA vale.
     */
    public function confirm(
        Request $request,
        ConfirmTwoFactorService $confirmar,
    ): RedirectResponse {
        /** @var User $usuario */
        $usuario = $request->user();

        $chave = 'confirma-2fa|'.$usuario->getKey();

        if (RateLimiter::tooManyAttempts($chave, self::TENTATIVAS_DE_CONFIRMACAO)) {
            throw ValidationException::withMessages([
                'codigo' => 'Tentativas demais. Espere um minuto e tente de novo.',
            ]);
        }

        $request->validate(['codigo' => ['required', 'string']], [], ['codigo' => 'código']);

        $codigo = str_replace(' ', '', trim((string) $request->string('codigo')));

        try {
            $confirmar->executar($usuario, $codigo);
        } catch (ValidationException) {
            RateLimiter::hit($chave);

            // A Action do Fortify põe o erro num errorBag próprio dela, que
            // o nosso formulário Inertia não lê. Retraduzir aqui evita uma
            // tela que recusa o código sem dizer nada.
            throw ValidationException::withMessages([
                'codigo' => 'Código inválido. Confira o aplicativo e tente de novo.',
            ]);
        }

        RateLimiter::clear($chave);

        return to_route('identity.2fa.edit')
            ->with('codigos_de_recuperacao', $usuario->refresh()->recoveryCodes())
            ->with('sucesso', 'Segundo fator ativado. Guarde os códigos de recuperação.');
    }

    /**
     * Renova os oito códigos, invalidando os anteriores.
     */
    public function recoveryCodes(
        Request $request,
        RegenerateRecoveryCodesService $regenerar,
    ): RedirectResponse {
        /** @var User $usuario */
        $usuario = $request->user();

        // Mesma exigência de senha atual da troca de senha: sessão
        // esquecida aberta não pode gerar uma lista nova de credenciais de
        // emergência e levá-la embora.
        $request->validate(
            ['current_password' => ['required', 'current_password']],
            [],
            ['current_password' => 'senha atual'],
        );

        $codigos = $regenerar->executar($usuario);

        return to_route('identity.2fa.edit')
            ->with('codigos_de_recuperacao', $codigos)
            ->with('sucesso', 'Códigos de recuperação renovados. Os anteriores não valem mais.');
    }

    /**
     * Desativa o 2FA — na prática, o primeiro passo de reconfigurar.
     */
    public function destroy(Request $request, DisableTwoFactorService $desativar): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $request->validate(
            ['current_password' => ['required', 'current_password']],
            [],
            ['current_password' => 'senha atual'],
        );

        $desativar->executar($usuario);

        return to_route('identity.2fa.edit')
            ->with('sucesso', $usuario->requiresTwoFactor()
                ? 'Segundo fator desativado. Seu papel exige 2FA, então configure novamente para continuar.'
                : 'Segundo fator desativado.');
    }

    /**
     * Deixa de confiar em todos os dispositivos lembrados.
     */
    public function forgetDevices(Request $request, RememberedDeviceService $dispositivos): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $dispositivos->esquecerTodos($usuario);

        return to_route('identity.2fa.edit')
            ->withCookie($dispositivos->cookieDeEsquecimento())
            ->with('sucesso', 'Todos os dispositivos terão de confirmar o código no próximo acesso.');
    }

    /**
     * O segredo em texto, para quem não consegue ler o QR (leitor de tela,
     * câmera quebrada, aplicativo de desktop).
     */
    private function chaveManual(User $usuario): string
    {
        return Fortify::currentEncrypter()->decrypt($usuario->two_factor_secret);
    }

    /**
     * @return list<array{device_id: string, ultimo_uso: string|null, expira_em: string, criado_em: string}>
     */
    private function dispositivosParaTela(User $usuario): array
    {
        return $usuario->rememberedDevices()
            ->valid()
            ->latest()
            ->get()
            ->map(fn ($d): array => [
                'device_id' => $d->device_id,
                'ultimo_uso' => $d->last_used_at?->toIso8601String(),
                'expira_em' => $d->expires_at->toIso8601String(),
                'criado_em' => $d->created_at->toIso8601String(),
            ])
            ->all();
    }
}
