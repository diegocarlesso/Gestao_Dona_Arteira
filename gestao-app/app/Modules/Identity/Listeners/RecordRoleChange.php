<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Events\UserRolesChanged;
use App\Modules\Identity\Services\RecordSecurityEvent;

/**
 * Mudança de papéis → trilha de segurança (pasta 26 §2).
 *
 * A mudança mais sensível do módulo, e a que **não** aparece na `audits`:
 * papel vive em tabela pivô, e o laravel-auditing audita colunas de
 * model. Sem esta linha, alguém ganhar `finance.manage` não deixaria
 * rastro em lugar nenhum.
 */
class RecordRoleChange
{
    public function __construct(private readonly RecordSecurityEvent $trilha) {}

    public function handle(UserRolesChanged $event): void
    {
        $de = $this->valores($event->de);
        $para = $this->valores($event->para);

        $this->trilha->record(
            SecurityEventType::UserRolesChanged,
            sobre: $event->usuario,
            context: [
                'de' => $de,
                'para' => $para,
                // O diff explícito é o que se lê numa investigação:
                // "ganhou admin" salta aos olhos, comparar duas listas
                // não. Quem amplia acesso é a pergunta; quem perde
                // acesso importa para entender uma reclamação de 403.
                'ganhou' => array_values(array_diff($para, $de)),
                'perdeu' => array_values(array_diff($de, $para)),
            ],
        );
    }

    /**
     * @param  list<Role>  $papeis
     * @return list<string>
     */
    private function valores(array $papeis): array
    {
        return array_map(static fn (Role $r): string => $r->value, $papeis);
    }
}
