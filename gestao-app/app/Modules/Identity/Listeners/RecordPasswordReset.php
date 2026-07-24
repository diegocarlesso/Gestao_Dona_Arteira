<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RecordSecurityEvent;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Senha redefinida por recuperação → trilha de segurança (pasta 26 §2).
 *
 * Distinto de `password.changed`, que é a troca feita por quem já estava
 * dentro: este nasce de um link enviado por e-mail, então é o caminho que
 * um invasor com acesso à caixa de e-mail usaria. Merece linha própria na
 * trilha, não a mesma do outro.
 */
class RecordPasswordReset
{
    public function __construct(private readonly RecordSecurityEvent $trilha) {}

    public function handle(PasswordReset $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->trilha->record(
            SecurityEventType::PasswordReset,
            sobre: $event->user,
            ator: $event->user,
        );
    }
}
