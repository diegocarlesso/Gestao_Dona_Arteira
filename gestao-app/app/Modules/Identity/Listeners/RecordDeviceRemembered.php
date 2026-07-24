<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Events\DeviceRemembered;
use App\Modules\Identity\Services\RecordSecurityEvent;

/**
 * Dispositivo confiado → trilha de segurança (pasta 26 §2).
 *
 * O `device_id` entra no contexto para que a entrada da trilha e a linha
 * de `two_factor_remembered_devices` sejam ligáveis. O token não entra —
 * nem o hash dele: a trilha é lida por quem tem `audit.view`, e ali não
 * pode haver nada que ajude a forjar o cookie.
 */
class RecordDeviceRemembered
{
    public function __construct(private readonly RecordSecurityEvent $trilha) {}

    public function handle(DeviceRemembered $event): void
    {
        $this->trilha->record(
            SecurityEventType::TwoFactorDeviceRemembered,
            sobre: $event->usuario,
            context: [
                'device_id' => $event->dispositivo->device_id,
                'expira_em' => $event->dispositivo->expires_at->toIso8601String(),
            ],
            ator: $event->usuario,
        );
    }
}
