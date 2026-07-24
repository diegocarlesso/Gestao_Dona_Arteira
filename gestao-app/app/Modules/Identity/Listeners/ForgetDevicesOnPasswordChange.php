<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Events\PasswordChanged;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RememberedDeviceService;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Trocar a senha deixa de confiar em todos os dispositivos (ADR-0021).
 *
 * É o que torna a revogação uma resposta de verdade a "acho que alguém
 * pegou meu computador": sem isto, trocar a senha não fecharia a porta que
 * o dispositivo lembrado deixou aberta, e o invasor continuaria pulando o
 * segundo fator com o cookie que já tem.
 *
 * Ouve os dois caminhos de propósito. O reset por e-mail é justamente o
 * usado por quem perdeu o controle da conta — deixá-lo de fora seria
 * esquecer o caso que mais precisa.
 */
class ForgetDevicesOnPasswordChange
{
    public function __construct(private readonly RememberedDeviceService $dispositivos) {}

    public function handle(PasswordChanged|PasswordReset $event): void
    {
        $usuario = $event instanceof PasswordChanged ? $event->usuario : $event->user;

        if ($usuario instanceof User) {
            $this->dispositivos->esquecerTodos($usuario);
        }
    }
}
