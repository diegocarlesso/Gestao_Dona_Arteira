<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RecordSecurityEvent;
use Illuminate\Auth\Events\Login;

/**
 * Entrada autorizada → trilha de segurança (pasta 26 §2).
 */
class RecordSuccessfulLogin
{
    public function __construct(private readonly RecordSecurityEvent $trilha) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->trilha->record(
            SecurityEventType::LoginOk,
            sobre: $event->user,
            context: [
                'guard' => $event->guard,
                // Sessão lembrada vive muito mais que a do navegador;
                // saber que a entrada nasceu de um "manter conectado"
                // muda a leitura de um acesso em horário estranho.
                'remember' => $event->remember,
            ],
            // O ator é a própria pessoa. Explícito porque o `Auth::user()`
            // que o serviço consultaria já está preenchido aqui, mas
            // depender dessa ordem seria frágil.
            ator: $event->user,
        );
    }
}
