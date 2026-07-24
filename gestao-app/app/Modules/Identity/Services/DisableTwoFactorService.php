<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Events\TwoFactorDisabled;
use App\Modules\Identity\Models\User;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * Desativa o segundo fator da conta (ADR-0021).
 *
 * Para papel que o exige (BR-804) isto não é uma saída: o middleware
 * `2fa.confirmado` volta a prender a pessoa na tela de configuração no
 * próximo pedido. Na prática, desativar é o primeiro passo de reconfigurar
 * — trocar de celular, reinstalar o aplicativo.
 */
class DisableTwoFactorService
{
    public function __construct(
        private readonly DisableTwoFactorAuthentication $desabilitar,
        private readonly RememberedDeviceService $dispositivos,
    ) {}

    public function executar(User $usuario, ?User $ator = null): User
    {
        ($this->desabilitar)($usuario);

        // Sem isto, desligar o 2FA deixaria para trás cookies que pulam um
        // desafio que não existe mais — e que voltariam a valer no momento
        // em que a pessoa reconfigurasse. Confiança some junto com o
        // segredo que a originou.
        $this->dispositivos->esquecerTodos($usuario);

        TwoFactorDisabled::dispatch($usuario->refresh(), $ator);

        return $usuario;
    }
}
