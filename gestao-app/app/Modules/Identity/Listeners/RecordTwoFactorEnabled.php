<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Events\TwoFactorEnabled;
use App\Modules\Identity\Services\RecordSecurityEvent;

/**
 * Segundo fator ativado → trilha de segurança (pasta 26 §2).
 */
class RecordTwoFactorEnabled
{
    public function __construct(private readonly RecordSecurityEvent $trilha) {}

    public function handle(TwoFactorEnabled $event): void
    {
        $this->trilha->record(
            SecurityEventType::TwoFactorEnabled,
            sobre: $event->usuario,
            context: [
                // Ativou porque o papel obriga (BR-804) ou por vontade
                // própria? Muda a leitura: adesão voluntária é sinal bom,
                // e some da conta se o papel mudar.
                'obrigatorio' => $event->usuario->requiresTwoFactor(),
            ],
            ator: $event->usuario,
        );
    }
}
