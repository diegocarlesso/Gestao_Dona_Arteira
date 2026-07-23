<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * O ciclo de vida da conta, conforme docs/18-Usuarios/README.md §3.
 *
 * Conta nunca é excluída — a auditoria referencia o usuário, e apagar a
 * linha transformaria a trilha em "alguém que não existe mais mexeu
 * nisso" (BR-008, BR-802). Desligamento é `Disabled`, não DELETE.
 */
enum UserStatus: string
{
    /** Convidada: existe, mas ainda não definiu senha nem 2FA. */
    case Invited = 'invited';

    /** Ativa: pode entrar. */
    case Active = 'active';

    /** Suspensa: bloqueio temporário (férias, afastamento). Reversível. */
    case Suspended = 'suspended';

    /** Desativada: desligamento. Não volta — cria-se conta nova. */
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'Convidada',
            self::Active => 'Ativa',
            self::Suspended => 'Suspensa',
            self::Disabled => 'Desativada',
        };
    }

    /**
     * Só conta ativa autentica. Qualquer outro estado nega o login —
     * inclusive `Invited`, que ainda não passou pela ativação.
     */
    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    /**
     * Transições permitidas a partir deste estado.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Invited => [self::Active, self::Disabled],
            self::Active => [self::Suspended, self::Disabled],
            self::Suspended => [self::Active, self::Disabled],
            // Desligamento é terminal: reativar uma conta desligada
            // ressuscitaria um acesso que alguém decidiu encerrar.
            self::Disabled => [],
        };
    }

    public function canTransitionTo(self $destino): bool
    {
        return in_array($destino, $this->allowedTransitions(), strict: true);
    }
}
