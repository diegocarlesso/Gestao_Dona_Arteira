<?php

declare(strict_types=1);

namespace Database\Seeders\Identity;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A primeira conta admin (docs/18-Usuarios/README.md §5).
 *
 * Sem ela, um banco recém-migrado não tem por onde entrar — e a saída
 * seria criar usuário por tinker em produção, sem papel e sem rastro.
 *
 * A senha **não** fica no código: vem de `ADMIN_INICIAL_SENHA` ou é
 * sorteada e impressa uma única vez na saída do comando. Em ambos os
 * casos a conta nasce com `must_change_password`, então a senha do
 * seeder só serve para a primeira entrada.
 */
class AdminInicialSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('identity.admin_inicial.email');

        // Idempotente: reexecutar o seeder num banco que já tem admin
        // não pode redefinir a senha de quem está usando o sistema.
        if (User::query()->where('email', $email)->exists()) {
            $this->command->warn("Admin inicial já existe ({$email}) — nada a fazer.");

            return;
        }

        $senhaDoAmbiente = config('identity.admin_inicial.senha');
        $senhaVeioDoAmbiente = is_string($senhaDoAmbiente) && $senhaDoAmbiente !== '';
        $senha = $senhaVeioDoAmbiente ? $senhaDoAmbiente : Str::password(20);

        $admin = User::query()->create([
            'name' => (string) config('identity.admin_inicial.nome'),
            'email' => $email,
            'password' => Hash::make($senha),
            'status' => UserStatus::Active,
        ]);

        $admin->forceFill([
            'email_verified_at' => now(),
            'must_change_password' => true,
        ])->save();

        $admin->assignRoleEnum(Role::Admin);

        $this->command->warn("Admin inicial criado: {$email}");

        if (! $senhaVeioDoAmbiente) {
            // Única vez que esta senha aparece em qualquer lugar. Não vai
            // para o log da aplicação — o console de deploy é efêmero.
            $this->command->warn("Senha provisória: {$senha}");
            $this->command->warn('Anote agora; troca obrigatória no primeiro acesso.');
        }
    }
}
