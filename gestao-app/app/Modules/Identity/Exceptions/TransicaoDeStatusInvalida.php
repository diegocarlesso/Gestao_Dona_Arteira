<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use App\Modules\Identity\Enums\UserStatus;
use DomainException;

/**
 * Transição de ciclo de vida que a pasta 18 §3 não permite.
 *
 * Carrega código estável (`identity.invalid_status_transition`) para o
 * handler mapear ao formato de erro da API (pasta 05 §4).
 */
class TransicaoDeStatusInvalida extends DomainException
{
    public const CODIGO = 'identity.invalid_status_transition';

    public function __construct(
        public readonly UserStatus $origem,
        public readonly UserStatus $destino,
    ) {
        parent::__construct(sprintf(
            'Uma conta %s não pode passar para %s.',
            mb_strtolower($origem->label()),
            mb_strtolower($destino->label()),
        ));
    }

    public static function de(UserStatus $origem, UserStatus $destino): self
    {
        return new self($origem, $destino);
    }
}
