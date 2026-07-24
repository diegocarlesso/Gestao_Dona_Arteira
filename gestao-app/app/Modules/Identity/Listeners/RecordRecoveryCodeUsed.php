<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Events\RecoveryCodeUsed;
use App\Modules\Identity\Services\RecordSecurityEvent;

/**
 * Entrada por código de recuperação → trilha de segurança (pasta 26 §2).
 */
class RecordRecoveryCodeUsed
{
    public function __construct(private readonly RecordSecurityEvent $trilha) {}

    public function handle(RecoveryCodeUsed $event): void
    {
        $this->trilha->record(
            SecurityEventType::TwoFactorRecoveryCodeUsed,
            sobre: $event->usuario,
            context: [
                // Quantos sobraram. Zero é uma conta que só volta a entrar
                // com intervenção de um admin — o gatilho de revisão do
                // ADR-0021 vigia justamente o uso repetido.
                'restantes' => $event->restantes,
            ],
            ator: $event->usuario,
        );
    }
}
