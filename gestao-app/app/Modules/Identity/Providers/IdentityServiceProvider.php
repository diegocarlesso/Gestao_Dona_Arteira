<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Events\PasswordChanged;
use App\Modules\Identity\Events\UserInvited;
use App\Modules\Identity\Events\UserRolesChanged;
use App\Modules\Identity\Events\UserStatusChanged;
use App\Modules\Identity\Listeners\RecordFailedLogin;
use App\Modules\Identity\Listeners\RecordInvitation;
use App\Modules\Identity\Listeners\RecordLogout;
use App\Modules\Identity\Listeners\RecordPasswordChange;
use App\Modules\Identity\Listeners\RecordPasswordReset;
use App\Modules\Identity\Listeners\RecordRoleChange;
use App\Modules\Identity\Listeners\RecordStatusChange;
use App\Modules\Identity\Listeners\RecordSuccessfulLogin;
use App\Modules\Identity\Listeners\UpdateLastLoginAt;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\UserPolicy;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
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

    /**
     * A trilha de segurança da pasta 26 §2, ligada evento a evento.
     *
     * Registrada aqui, e não num EventServiceProvider global, porque a
     * trilha de autenticação é assunto do Identity — o ADR-0020 diz que
     * cada módulo registra o que é seu. Um módulo novo que precise ouvir
     * `Login` faz isso no provider dele.
     *
     * @var array<class-string, list<class-string>>
     */
    private const OUVINTES = [
        // Dois ouvintes no mesmo evento, de propósito: a trilha é
        // engolida por try/catch para não trancar ninguém para fora, a
        // coluna do perfil não é. Ver RecordSecurityEvent.
        Login::class => [RecordSuccessfulLogin::class, UpdateLastLoginAt::class],
        Failed::class => [RecordFailedLogin::class],
        Logout::class => [RecordLogout::class],
        PasswordReset::class => [RecordPasswordReset::class],
        PasswordChanged::class => [RecordPasswordChange::class],
        UserStatusChanged::class => [RecordStatusChange::class],
        UserRolesChanged::class => [RecordRoleChange::class],
        UserInvited::class => [RecordInvitation::class],
    ];

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        Gate::policy(User::class, UserPolicy::class);

        foreach (self::OUVINTES as $evento => $ouvintes) {
            foreach ($ouvintes as $ouvinte) {
                Event::listen($evento, $ouvinte);
            }
        }

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
