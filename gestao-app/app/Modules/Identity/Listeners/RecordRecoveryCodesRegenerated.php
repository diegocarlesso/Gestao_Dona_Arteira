<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Events\RecoveryCodesRegenerated;
use App\Modules\Identity\Services\RecordSecurityEvent;

/**
 * Códigos de recuperação renovados → trilha de segurança (pasta 26 §2).
 *
 * Os códigos em si jamais entram no contexto — seria guardar a chave
 * reserva dentro do registro de que a fechadura foi trocada.
 */
class RecordRecoveryCodesRegenerated
{
    public function __construct(private readonly RecordSecurityEvent $trilha) {}

    public function handle(RecoveryCodesRegenerated $event): void
    {
        $this->trilha->record(
            SecurityEventType::TwoFactorRecoveryCodesRegenerated,
            sobre: $event->usuario,
            ator: $event->usuario,
        );
    }
}
