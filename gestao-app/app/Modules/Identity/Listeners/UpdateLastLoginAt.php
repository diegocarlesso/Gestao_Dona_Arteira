<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Preenche `last_login_at` (pasta 18 §3.1).
 *
 * Listener separado do que grava a trilha, de propósito: são
 * responsabilidades diferentes e falham de formas diferentes. A trilha de
 * segurança é engolida por `try/catch` para não trancar ninguém para
 * fora; a data do último acesso é uma coluna do perfil, e se a gravação
 * dela falhar o erro deve subir como qualquer outro.
 *
 * A coluna existia desde a migration do Identity e nunca era preenchida.
 * Ela serve a duas perguntas concretas: "esta conta ainda é usada?" na
 * revisão trimestral de acessos (pasta 25) e "quando foi a última vez que
 * esta pessoa entrou?" na investigação de um incidente.
 */
class UpdateLastLoginAt
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        // `forceFill` + `saveQuietly`: não é mudança que a pessoa fez, é
        // consequência automática de ter entrado. Passar pela auditoria
        // encheria a `audits` de uma linha por login, afogando as
        // mudanças que importam — e o fato do login já está em
        // `security_events`.
        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
