<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Provider do módulo Identity.
 *
 * Cada módulo registra o que é seu por aqui, sem pacote de "module
 * system" — PSR-4 + Service Provider próprio (pasta 05 §2).
 */
class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // BR-801, negação por padrão: `Gate::before` só é consultado
        // ANTES das Policies, e apenas para conceder. Retornar `null`
        // (não `false`) mantém a decisão com a Policy de cada recurso —
        // devolver `false` aqui bloquearia tudo o que o admin não tem,
        // atropelando as Policies dos demais papéis.
        Gate::before(static function (mixed $user, string $ability): ?bool {
            return $user instanceof User
                && $user->hasRoleEnum(Role::Admin)
                    ? true
                    : null;
        });
    }
}
