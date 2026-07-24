<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Os oito códigos de recuperação foram renovados (ADR-0021).
 *
 * Invalida todos os anteriores de uma vez. Numa investigação, é o fato que
 * separa "a pessoa perdeu a lista e refez" de "alguém com a sessão da
 * pessoa refez a lista para ficar com uma cópia válida".
 */
class RecoveryCodesRegenerated
{
    use Dispatchable;

    public function __construct(
        public readonly User $usuario,
    ) {}
}
