<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Segundo fator passou a valer para a conta (BR-804, ADR-0021).
 *
 * Disparado na **confirmação**, não na geração do segredo. A diferença
 * importa: gerar o segredo é abrir a tela de configuração, coisa que
 * alguém pode fazer e abandonar; confirmar é provar que o aplicativo
 * autenticador funciona. Só o segundo muda a proteção da conta.
 *
 * Evento nosso, e não o `TwoFactorAuthenticationConfirmed` do Fortify, de
 * propósito: o ADR-0021 prevê trocar o pacote por `pragmarx/google2fa`
 * direto se as Actions quebrarem, e a trilha de segurança não pode parar
 * de gravar junto. Ouvir a classe do pacote amarraria a pasta 26 a ele.
 */
class TwoFactorEnabled
{
    use Dispatchable;

    public function __construct(
        public readonly User $usuario,
    ) {}
}
