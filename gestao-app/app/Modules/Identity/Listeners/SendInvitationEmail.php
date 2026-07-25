<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Events\UserInvited;
use App\Modules\Identity\Notifications\ConviteDeAcesso;

/**
 * Conta convidada → e-mail de convite (pasta 18 §3).
 *
 * O ouvinte é síncrono e a **notificação** é que vai para a fila. A
 * diferença importa: enfileirar o ouvinte faria o `UserInvited` chegar ao
 * worker, e aí a trilha de segurança dependeria da fila estar viva.
 * Assim, o registro do convite é imediato e só o envio é adiado.
 */
class SendInvitationEmail
{
    public function handle(UserInvited $event): void
    {
        $event->usuario->notify(new ConviteDeAcesso);
    }
}
