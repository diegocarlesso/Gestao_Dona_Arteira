<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Events\DeviceRemembered;
use App\Modules\Identity\Events\PasswordChanged;
use App\Modules\Identity\Events\RecoveryCodesRegenerated;
use App\Modules\Identity\Events\RecoveryCodeUsed;
use App\Modules\Identity\Events\TwoFactorDisabled;
use App\Modules\Identity\Events\TwoFactorEnabled;
use App\Modules\Identity\Events\UserInvited;
use App\Modules\Identity\Events\UserRolesChanged;
use App\Modules\Identity\Events\UserStatusChanged;
use App\Modules\Identity\Listeners\ActivateInvitedAccount;
use App\Modules\Identity\Listeners\ForgetDevicesOnPasswordChange;
use App\Modules\Identity\Listeners\RecordDeviceRemembered;
use App\Modules\Identity\Listeners\RecordFailedLogin;
use App\Modules\Identity\Listeners\RecordInvitation;
use App\Modules\Identity\Listeners\RecordLogout;
use App\Modules\Identity\Listeners\RecordPasswordChange;
use App\Modules\Identity\Listeners\RecordPasswordReset;
use App\Modules\Identity\Listeners\RecordRecoveryCodesRegenerated;
use App\Modules\Identity\Listeners\RecordRecoveryCodeUsed;
use App\Modules\Identity\Listeners\RecordRoleChange;
use App\Modules\Identity\Listeners\RecordStatusChange;
use App\Modules\Identity\Listeners\RecordSuccessfulLogin;
use App\Modules\Identity\Listeners\RecordTwoFactorDisabled;
use App\Modules\Identity\Listeners\RecordTwoFactorEnabled;
use App\Modules\Identity\Listeners\SendInvitationEmail;
use App\Modules\Identity\Listeners\UpdateLastLoginAt;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\UserPolicy;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider as TwoFactorAuthenticationProviderContract;
use Laravel\Fortify\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;

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

        // BR-008: cadastro com movimento nunca é excluído fisicamente —
        // é arquivado. A regra vale para o sistema inteiro, e sem esta
        // linha o atalho do admin responderia `true` a qualquer
        // `can('delete', ...)`, convidando alguém a construir a tela de
        // exclusão que a regra proíbe. A Policy de cada recurso decide;
        // as do catálogo negam sempre.
        'delete',
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

        // Trocar a senha, por qualquer caminho, revoga os dispositivos
        // lembrados (ADR-0021) — além de registrar o fato.
        // `ActivateInvitedAccount` por último de propósito: se a promoção
        // Invited → Active falhasse, o registro do reset já estaria na
        // trilha. O contrário deixaria uma conta ativada sem rastro do que
        // a ativou.
        PasswordReset::class => [
            RecordPasswordReset::class,
            ForgetDevicesOnPasswordChange::class,
            ActivateInvitedAccount::class,
        ],
        PasswordChanged::class => [RecordPasswordChange::class, ForgetDevicesOnPasswordChange::class],

        UserStatusChanged::class => [RecordStatusChange::class],
        UserRolesChanged::class => [RecordRoleChange::class],
        UserInvited::class => [RecordInvitation::class, SendInvitationEmail::class],

        // 2FA (ADR-0021). Eventos nossos, não os do Fortify: o gatilho de
        // revisão do ADR prevê trocar o pacote, e a trilha não pode parar
        // de gravar junto com essa troca.
        TwoFactorEnabled::class => [RecordTwoFactorEnabled::class],
        TwoFactorDisabled::class => [RecordTwoFactorDisabled::class],
        RecoveryCodesRegenerated::class => [RecordRecoveryCodesRegenerated::class],
        RecoveryCodeUsed::class => [RecordRecoveryCodeUsed::class],
        DeviceRemembered::class => [RecordDeviceRemembered::class],
    ];

    /**
     * O que o `FortifyServiceProvider` faria — e só isso.
     *
     * O provider do Fortify nunca é registrado (ADR-0021): ele está em
     * `extra.laravel.dont-discover`, porque o pacote se auto-registraria
     * pelo package discovery. O preço de desligá-lo é que os bindings de
     * que as quatro Actions de 2FA dependem passam a ser nossos.
     *
     * É um binding só, contra os ~25 de resposta HTTP que o provider dele
     * registraria — a proporção é a própria justificativa do ADR-0021
     * para usar o pacote sem o seu HTTP.
     */
    public function register(): void
    {
        $this->app->singleton(
            TwoFactorAuthenticationProviderContract::class,
            static fn ($app): TwoFactorAuthenticationProvider => new TwoFactorAuthenticationProvider(
                $app->make(Google2FA::class),
                // O cache é o que impede reusar o mesmo código TOTP dentro
                // da janela de validade — sem ele, um código interceptado
                // valeria os 30 segundos inteiros para quem o repetisse.
                $app->make(CacheRepository::class),
            ),
        );
    }

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
