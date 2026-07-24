<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Events\TwoFactorEnabled;
use App\Modules\Identity\Models\User;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;

/**
 * Confirma o 2FA com um código do aplicativo — é aqui que ele passa a
 * valer (ADR-0021).
 *
 * A Action do Fortify lança `ValidationException` no campo `code` se o
 * código não bater, o que o Inertia devolve como erro de formulário sem
 * nada a traduzir aqui.
 */
class ConfirmTwoFactorService
{
    public function __construct(
        private readonly ConfirmTwoFactorAuthentication $confirmar,
        private readonly RememberedDeviceService $dispositivos,
    ) {}

    public function executar(User $usuario, string $codigo): User
    {
        ($this->confirmar)($usuario, $codigo);

        // Confiança concedida a um segredo anterior não vale para o novo.
        // Reconfigurar o 2FA (trocar de celular, por exemplo) passa por
        // aqui, e os dispositivos lembrados na configuração antiga não
        // podem sobreviver à troca.
        $this->dispositivos->esquecerTodos($usuario);

        TwoFactorEnabled::dispatch($usuario->refresh());

        return $usuario;
    }
}
