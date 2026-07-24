<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Events\UserInvited;
use App\Modules\Identity\Services\RecordSecurityEvent;

/**
 * Conta criada → trilha de segurança (pasta 26 §2).
 *
 * Registra a criação com os papéis que a conta já nasceu tendo — um
 * convite que sai com `admin` é o tipo de coisa que precisa aparecer sem
 * que ninguém tenha de cruzar duas tabelas para descobrir.
 *
 * Este listener **não envia e-mail**. O envio é outro ouvinte do mesmo
 * evento, ainda pendente (pasta 18 §3): a trilha não pode depender de
 * SMTP configurado para registrar que a conta existe.
 */
class RecordInvitation
{
    public function __construct(private readonly RecordSecurityEvent $trilha) {}

    public function handle(UserInvited $event): void
    {
        $this->trilha->record(
            SecurityEventType::UserInvited,
            sobre: $event->usuario,
            context: [
                'papeis' => array_map(
                    static fn (Role $r): string => $r->value,
                    $event->usuario->roleEnums(),
                ),
                'email' => $event->usuario->email,
            ],
        );
    }
}
