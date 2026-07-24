<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Events\TwoFactorDisabled;
use App\Modules\Identity\Services\RecordSecurityEvent;

/**
 * Segundo fator desativado → trilha de segurança (pasta 26 §2).
 */
class RecordTwoFactorDisabled
{
    public function __construct(private readonly RecordSecurityEvent $trilha) {}

    public function handle(TwoFactorDisabled $event): void
    {
        $this->trilha->record(
            SecurityEventType::TwoFactorDisabled,
            sobre: $event->usuario,
            context: [
                // Desativar numa conta que o exige deixa a pessoa presa na
                // tela de configuração até reconfigurar — não é uma brecha,
                // mas é o estado que explica um "não consigo entrar".
                'obrigatorio' => $event->usuario->requiresTwoFactor(),
            ],
            ator: $event->ator ?? $event->usuario,
        );
    }
}
