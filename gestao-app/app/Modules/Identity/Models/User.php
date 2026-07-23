<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Enums\UserStatus;
use Database\Factories\Identity\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Usuário do ERP — conta nominal e individual (pasta 18 §3).
 *
 * Auditável desde a primeira linha (ADR-0012): mudança de papel, de
 * status ou de e-mail precisa deixar rastro de quem fez e quando.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property UserStatus $status
 * @property string $password
 * @property string|null $two_factor_secret
 * @property array<int, string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $password_changed_at
 * @property bool $must_change_password
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Campos que NUNCA entram no diff da auditoria (pasta 26 §5).
     *
     * Um `audits` com hash de senha ou segredo TOTP transforma a trilha
     * — que muita gente pode ler com `audit.view` — em superfície de
     * ataque. `password` já sai por estar em $hidden, mas a exclusão
     * explícita não depende disso continuar verdadeiro.
     *
     * @var array<int, string>
     */
    protected $auditExclude = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // O ULID público nasce com a linha. Deixar isso para o Service
        // que cria o usuário significaria que qualquer outro caminho de
        // criação — seeder, factory, tinker — geraria linha sem ID
        // público, e a falha só apareceria ao expor o registro na API.
        static::creating(function (self $user): void {
            $user->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Nenhum papel entra pela porta de trás: apenas os valores do enum
     * Role existem. `HasRoles::assignRole()` aceita string livre e
     * criaria papel novo em silêncio se o nome viesse errado.
     */
    public function assignRoleEnum(Role ...$roles): self
    {
        $this->assignRole(array_map(static fn (Role $r): string => $r->value, $roles));

        return $this;
    }

    public function hasRoleEnum(Role $role): bool
    {
        return $this->hasRole($role->value);
    }

    /**
     * Os papéis do usuário como enum, ignorando qualquer nome que não
     * corresponda a um caso conhecido.
     *
     * @return list<Role>
     */
    public function roleEnums(): array
    {
        return array_values(array_filter(
            $this->getRoleNames()
                ->map(static fn (string $nome): ?Role => Role::tryFrom($nome))
                ->all(),
        ));
    }

    /**
     * 2FA é obrigatório se QUALQUER papel do usuário o exigir (BR-804).
     */
    public function requiresTwoFactor(): bool
    {
        foreach ($this->roleEnums() as $role) {
            if ($role->requiresTwoFactor()) {
                return true;
            }
        }

        return false;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Só conta ativa autentica (pasta 18 §3). Consultado no login;
     * suspender alguém tem de barrar a entrada, não apenas sinalizar.
     */
    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate();
    }
}
