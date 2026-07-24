<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/**
 * Começa a configuração do 2FA: gera segredo e os oito códigos (ADR-0021).
 *
 * **Não ativa nada.** Enquanto `two_factor_confirmed_at` for nulo, a conta
 * segue sem segundo fator e o middleware continua prendendo quem o deve —
 * é o `ConfirmTwoFactorService` que fecha o ciclo. A separação existe
 * porque abrir a tela e desistir tem de ser inofensivo: se abrir já
 * ativasse, quem fechasse o navegador no meio ficaria trancado do lado de
 * fora, sem aplicativo configurado e com o 2FA exigido no próximo login.
 *
 * Por isso não há evento de domínio aqui: gerar segredo não muda a
 * proteção da conta, e a trilha da pasta 26 registra fatos, não rascunhos.
 */
class StartTwoFactorSetupService
{
    public function __construct(
        private readonly EnableTwoFactorAuthentication $habilitar,
    ) {}

    /**
     * Idempotente por escolha do Fortify: chamar de novo com um segredo já
     * existente não o troca (a menos que `$forcar`). Recarregar a tela de
     * configuração não invalida o QR que a pessoa já apontou a câmera.
     */
    public function executar(User $usuario, bool $forcar = false): User
    {
        ($this->habilitar)($usuario, $forcar);

        return $usuario->refresh();
    }
}
