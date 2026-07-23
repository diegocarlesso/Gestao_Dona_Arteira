<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Conta mudou de estado no ciclo de vida (pasta 18 §3).
 *
 * Alimenta a trilha de segurança da pasta 26 e, no futuro, o runbook de
 * offboarding (revogar tokens, trocar segredos compartilhados).
 */
class UserStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly User $usuario,
        public readonly UserStatus $de,
        public readonly UserStatus $para,
    ) {}
}
