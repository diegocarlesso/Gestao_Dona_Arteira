<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\RememberedDevice;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Um dispositivo passou a dispensar o desafio TOTP por 30 dias (ADR-0021).
 *
 * Sensível, e o ADR-0021 é explícito sobre o motivo: cada dispositivo
 * lembrado são 30 dias em que o segundo fator deixa de proteger aquele
 * login. Saber quando e de onde a confiança foi concedida é o mínimo que
 * compensa o atrito que se poupou — e é o primeiro lugar onde se olha
 * quando uma conta parece ter sido acessada por outra pessoa.
 */
class DeviceRemembered
{
    use Dispatchable;

    public function __construct(
        public readonly User $usuario,
        public readonly RememberedDevice $dispositivo,
    ) {}
}
