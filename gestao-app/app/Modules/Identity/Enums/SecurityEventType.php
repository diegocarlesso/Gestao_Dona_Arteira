<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * Os fatos que a trilha de segurança registra (pasta 26 §2).
 *
 * Formato `assunto.fato`, no mesmo espírito do `modulo.acao` das
 * permissões. Acrescentar um caso aqui não exige migration: a coluna é
 * VARCHAR justamente porque uma tabela que só cresce não deve precisar de
 * ALTER TABLE para aprender um evento novo.
 */
enum SecurityEventType: string
{
    case LoginOk = 'login.ok';
    case LoginFailed = 'login.failed';
    case Logout = 'logout';
    case PasswordChanged = 'password.changed';
    case PasswordReset = 'password.reset';
    case TwoFactorEnabled = 'two_factor.enabled';
    case TwoFactorDisabled = 'two_factor.disabled';
    case TwoFactorRecoveryCodesRegenerated = 'two_factor.recovery_regenerated';
    case TwoFactorRecoveryCodeUsed = 'two_factor.recovery_used';
    case TwoFactorDeviceRemembered = 'two_factor.device_remembered';
    case PermissionDenied = 'permission.denied';
    case UserInvited = 'user.invited';
    case UserStatusChanged = 'user.status_changed';
    case UserRolesChanged = 'user.roles_changed';

    public function label(): string
    {
        return match ($this) {
            self::LoginOk => 'Entrada autorizada',
            self::LoginFailed => 'Tentativa de entrada recusada',
            self::Logout => 'Saída',
            self::PasswordChanged => 'Senha alterada',
            self::PasswordReset => 'Senha redefinida por recuperação',
            self::TwoFactorEnabled => 'Segundo fator ativado',
            self::TwoFactorDisabled => 'Segundo fator desativado',
            self::TwoFactorRecoveryCodesRegenerated => 'Códigos de recuperação renovados',
            self::TwoFactorRecoveryCodeUsed => 'Entrada por código de recuperação',
            self::TwoFactorDeviceRemembered => 'Dispositivo passou a ser confiado',
            self::PermissionDenied => 'Acesso negado por permissão',
            self::UserInvited => 'Conta criada e convidada',
            self::UserStatusChanged => 'Situação da conta alterada',
            self::UserRolesChanged => 'Papéis alterados',
        };
    }

    /**
     * O evento merece atenção de quem administra?
     *
     * Serve à tela de consulta e a alertas futuros. `login.ok` e `logout`
     * são o ruído de fundo de um sistema saudável; os demais são o que se
     * procura quando algo aconteceu.
     */
    public function isSensitive(): bool
    {
        return match ($this) {
            self::LoginOk, self::Logout => false,
            default => true,
        };
    }
}
