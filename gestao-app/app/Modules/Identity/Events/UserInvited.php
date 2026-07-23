<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Conta criada e aguardando ativação (pasta 18 §3).
 *
 * Evento é a superfície pública do módulo (ADR-0020): quem quiser
 * mandar o e-mail de convite ouve isto, sem que Identity precise
 * conhecer o canal de envio.
 */
class UserInvited
{
    use Dispatchable;

    public function __construct(public readonly User $usuario) {}
}
