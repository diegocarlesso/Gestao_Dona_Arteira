<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RecordSecurityEvent;
use Illuminate\Auth\Events\Logout;

/**
 * Saída → trilha de segurança (pasta 26 §2).
 *
 * Pareado com `login.ok`, delimita a duração de uma sessão. Sem o par, um
 * acesso indevido que durou horas parece igual a um que durou um minuto.
 */
class RecordLogout
{
    public function __construct(private readonly RecordSecurityEvent $trilha) {}

    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->trilha->record(
            SecurityEventType::Logout,
            sobre: $event->user,
            context: ['guard' => $event->guard],
            ator: $event->user,
        );
    }
}
