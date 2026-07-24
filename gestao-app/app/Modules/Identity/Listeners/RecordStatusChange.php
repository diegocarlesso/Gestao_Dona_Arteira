<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Events\UserStatusChanged;
use App\Modules\Identity\Services\RecordSecurityEvent;

/**
 * Mudança de situação da conta → trilha de segurança (pasta 26 §2).
 *
 * A `audits` já registra a coluna `status` mudando. Este evento existe
 * para que a pergunta "quem suspendeu quem, e quando" tenha resposta num
 * único lugar, junto dos logins — em vez de exigir cruzar duas tabelas
 * com formatos diferentes durante uma investigação.
 */
class RecordStatusChange
{
    public function __construct(private readonly RecordSecurityEvent $trilha) {}

    public function handle(UserStatusChanged $event): void
    {
        $this->trilha->record(
            SecurityEventType::UserStatusChanged,
            sobre: $event->usuario,
            context: [
                'de' => $event->de->value,
                'para' => $event->para->value,
                // Suspender alguém e ele deixar de autenticar é o efeito
                // que importa; registrar isso poupa quem lê a trilha de
                // ter de conhecer a máquina de estados da pasta 18.
                'autentica_depois' => $event->para->canAuthenticate(),
            ],
        );
    }
}
