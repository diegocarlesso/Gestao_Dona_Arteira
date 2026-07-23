<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Papéis de uma conta foram alterados (pasta 19 §2).
 *
 * Existe porque o `laravel-auditing` audita colunas da tabela, e papel
 * vive em tabela pivô — a mudança mais sensível do módulo não apareceria
 * na trilha sem este evento.
 */
class UserRolesChanged
{
    use Dispatchable;

    /**
     * @param  list<Role>  $de
     * @param  list<Role>  $para
     */
    public function __construct(
        public readonly User $usuario,
        public readonly array $de,
        public readonly array $para,
    ) {}
}
