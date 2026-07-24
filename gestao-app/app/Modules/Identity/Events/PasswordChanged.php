<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Senha trocada por quem já estava autenticado (pasta 18 §3).
 *
 * Distinto do `PasswordReset` do Laravel, que nasce de um link enviado
 * por e-mail: aquele é o caminho que um invasor com acesso à caixa de
 * e-mail usaria, este exige conhecer a senha atual. A trilha da pasta 26
 * separa os dois porque a leitura de cada um é diferente.
 *
 * O `laravel-auditing` não serve aqui: `password` está em `$auditExclude`
 * — e deve continuar —, então a troca não deixa rastro na `audits`.
 */
class PasswordChanged
{
    use Dispatchable;

    public function __construct(
        public readonly User $usuario,
        /** Era uma troca imposta pelo primeiro acesso, não voluntária? */
        public readonly bool $obrigatoria = false,
    ) {}
}
