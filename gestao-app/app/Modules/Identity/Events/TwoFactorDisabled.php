<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Segundo fator deixou de valer para a conta (ADR-0021).
 *
 * Para papel que exige 2FA (BR-804), desativar não libera o acesso: o
 * middleware `2fa.confirmado` volta a prender a pessoa na tela de
 * configuração. Desativar é, na prática, "vou reconfigurar" — trocar de
 * celular, por exemplo.
 */
class TwoFactorDisabled
{
    use Dispatchable;

    public function __construct(
        public readonly User $usuario,
        /** Quem desativou, quando não foi a própria pessoa. */
        public readonly ?User $ator = null,
    ) {}
}
