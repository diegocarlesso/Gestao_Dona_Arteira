<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Events\UserStatusChanged;
use App\Modules\Identity\Exceptions\TransicaoDeStatusInvalida;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Move a conta entre os estados do ciclo de vida (pasta 18 §3).
 *
 * A transição é validada aqui, no domínio, e não no controller nem na
 * tela: o mesmo caminho vale para um comando de console, um job ou uma
 * futura rotina de offboarding.
 */
class ChangeUserStatusService
{
    public function handle(User $usuario, UserStatus $destino): User
    {
        $origem = $usuario->status;

        if ($origem === $destino) {
            return $usuario;
        }

        if (! $origem->canTransitionTo($destino)) {
            throw TransicaoDeStatusInvalida::de($origem, $destino);
        }

        return DB::transaction(function () use ($usuario, $origem, $destino): User {
            $usuario->update(['status' => $destino]);

            event(new UserStatusChanged($usuario, $origem, $destino));

            return $usuario->fresh();
        });
    }
}
