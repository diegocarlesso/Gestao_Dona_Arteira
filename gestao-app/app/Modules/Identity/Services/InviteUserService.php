<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Events\UserInvited;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Cria uma conta nova em estado `invited` (pasta 18 §3).
 *
 * A conta nasce **sem senha utilizável**: recebe um valor aleatório que
 * ninguém conhece. Quem entra é a pessoa, definindo a própria senha pelo
 * fluxo de "esqueci minha senha" — assim a senha nunca transita por
 * e-mail, console ou conversa.
 */
class InviteUserService
{
    /**
     * @param  list<Role>  $papeis
     */
    public function handle(string $nome, string $email, array $papeis): User
    {
        return DB::transaction(function () use ($nome, $email, $papeis): User {
            $usuario = User::query()->create([
                'name' => $nome,
                'email' => $email,
                // Senha impossível de adivinhar e que ninguém viu. O
                // acesso se dá pela redefinição, não por este valor.
                'password' => Hash::make(Str::password(32)),
                'status' => UserStatus::Invited,
            ]);

            $usuario->forceFill(['must_change_password' => false])->save();

            if ($papeis !== []) {
                $usuario->assignRoleEnum(...$papeis);
            }

            event(new UserInvited($usuario));

            return $usuario->fresh();
        });
    }
}
