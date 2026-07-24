<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Events\PasswordChanged;
use App\Modules\Identity\Services\RecordSecurityEvent;

/**
 * Troca de senha → trilha de segurança (pasta 26 §2).
 *
 * A senha em si nunca chega aqui, e não deve: o evento carrega o usuário,
 * não a credencial.
 */
class RecordPasswordChange
{
    public function __construct(private readonly RecordSecurityEvent $trilha) {}

    public function handle(PasswordChanged $event): void
    {
        $this->trilha->record(
            SecurityEventType::PasswordChanged,
            sobre: $event->usuario,
            context: [
                // Distingue a troca do primeiro acesso, que todo mundo faz
                // uma vez, da troca voluntária — que numa investigação
                // pode ser o invasor trancando o dono para fora.
                'obrigatoria' => $event->obrigatoria,
            ],
            ator: $event->usuario,
        );
    }
}
