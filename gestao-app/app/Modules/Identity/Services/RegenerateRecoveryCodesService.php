<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Events\RecoveryCodesRegenerated;
use App\Modules\Identity\Models\User;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * Renova os oito códigos de recuperação, invalidando os anteriores
 * (ADR-0021).
 *
 * Os dispositivos lembrados **não** são revogados aqui: renovar a lista de
 * códigos não troca o segredo TOTP, então a posse do segundo fator que
 * originou cada confiança continua provada. Revogar seria punir quem faz a
 * coisa certa (guardar códigos novos) com um desafio em cada máquina.
 */
class RegenerateRecoveryCodesService
{
    public function __construct(
        private readonly GenerateNewRecoveryCodes $gerar,
    ) {}

    /**
     * @return list<string> os códigos novos, em claro — é a única vez que
     *                      podem ser mostrados
     */
    public function executar(User $usuario): array
    {
        ($this->gerar)($usuario);

        $usuario->refresh();

        RecoveryCodesRegenerated::dispatch($usuario);

        /** @var list<string> */
        return $usuario->recoveryCodes();
    }
}
