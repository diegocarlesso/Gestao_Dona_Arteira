<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\UserPolicy;
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
    /**
     * Habilidades que o atalho do admin NÃO pode conceder.
     *
     * `Gate::before` roda antes das Policies e curto-circuita a decisão.
     * Isso é o que se quer na maior parte dos casos — mas destruiria as
     * regras que protegem o próprio autor da ação: sem esta lista, um
     * admin conseguiria suspender a própria conta (trancando-se do lado
     * de fora) ou se promover, porque a Policy nem seria consultada.
     *
     * @var list<string>
     */
    private const SEMPRE_PELA_POLICY = [
        'changeStatus',
        'assignRoles',
    ];

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        Gate::policy(User::class, UserPolicy::class);

        // BR-801, negação por padrão. Retornar `null` (não `false`)
        // mantém a decisão com a Policy de cada recurso — devolver
        // `false` aqui bloquearia tudo o que o admin não tem,
        // atropelando as Policies dos demais papéis.
        Gate::before(static function (mixed $user, string $ability): ?bool {
            if (in_array($ability, self::SEMPRE_PELA_POLICY, strict: true)) {
                return null;
            }

            return $user instanceof User && $user->hasRoleEnum(Role::Admin)
                ? true
                : null;
        });
    }
}
