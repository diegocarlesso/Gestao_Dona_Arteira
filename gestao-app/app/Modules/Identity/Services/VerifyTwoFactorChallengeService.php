<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Events\RecoveryCodeUsed;
use App\Modules\Identity\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Confere o segundo fator no desafio de login (ADR-0021).
 *
 * Os dois caminhos não são equivalentes, e a diferença é o coração do
 * mecanismo de dispositivo lembrado: um TOTP válido prova posse do
 * aplicativo **agora**, e por isso pode confiar no dispositivo; um código
 * de recuperação prova só posse da lista impressa, que é o caminho de
 * "perdi o celular" — conceder 30 dias de dispensa justamente aí anularia
 * a proteção. Quem decide isso é o controller, com base em qual método
 * respondeu; este Service só informa qual foi.
 */
class VerifyTwoFactorChallengeService
{
    public function __construct(
        private readonly TwoFactorAuthenticationProvider $provider,
    ) {}

    /**
     * O provider do Fortify guarda em cache os códigos já usados dentro da
     * janela, então o mesmo TOTP não entra duas vezes — quem interceptar
     * um código não o reaproveita nos segundos que lhe restam.
     */
    public function porTotp(User $usuario, string $codigo): bool
    {
        if ($usuario->two_factor_secret === null) {
            return false;
        }

        return $this->provider->verify(
            Fortify::currentEncrypter()->decrypt($usuario->two_factor_secret),
            $codigo,
        );
    }

    /**
     * Consome o código: usar um o substitui por outro, mantendo sempre
     * oito. Uso único de verdade — o mesmo papel não entra duas vezes.
     */
    public function porCodigoDeRecuperacao(User $usuario, string $codigo): bool
    {
        if ($usuario->two_factor_recovery_codes === null) {
            return false;
        }

        $encontrado = null;

        foreach ($usuario->recoveryCodes() as $valido) {
            // `hash_equals` em vez de `in_array`: comparar credencial em
            // tempo constante. O laço percorre todos mesmo depois de achar,
            // para o tempo de resposta não contar em que posição estava.
            if (hash_equals((string) $valido, $codigo)) {
                $encontrado = (string) $valido;
            }
        }

        if ($encontrado === null) {
            return false;
        }

        $usuario->replaceRecoveryCode($encontrado);

        RecoveryCodeUsed::dispatch(
            $usuario,
            // Sempre oito, por ora — a contagem existe para o dia em que a
            // política mudar para consumir sem repor, e para que a leitura
            // da trilha não dependa de saber disso de cabeça.
            count($usuario->refresh()->recoveryCodes()),
        );

        return true;
    }
}
