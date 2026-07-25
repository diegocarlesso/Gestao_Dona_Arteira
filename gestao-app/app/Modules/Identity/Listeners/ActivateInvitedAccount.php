<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ChangeUserStatusService;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Definir a senha **é** a ativação da conta convidada (pasta 18 §3).
 *
 * Sem este ouvinte o convite não fecha o ciclo: a pessoa recebe o e-mail,
 * define a senha, tenta entrar — e o middleware `conta.ativa` a expulsa,
 * porque `Invited` não autentica. Pior, a mensagem de erro a manda
 * "verificar o convite enviado por e-mail", ou seja, de volta ao ponto de
 * partida. Um laço fechado que só um admin destravaria, mexendo no status
 * à mão.
 *
 * O diagrama da pasta 18 §3 sempre disse que a ativação acontece ao
 * definir a senha; faltava alguém executá-la.
 *
 * Passa pelo `ChangeUserStatusService` em vez de escrever a coluna: é ele
 * que valida a transição contra `allowedTransitions()` e dispara
 * `UserStatusChanged`, que a trilha da pasta 26 registra. Ativação sem
 * rastro seria uma conta que ganha acesso sem ninguém saber quando.
 */
class ActivateInvitedAccount
{
    public function __construct(private readonly ChangeUserStatusService $status) {}

    public function handle(PasswordReset $event): void
    {
        $usuario = $event->user;

        // Só o convidado é promovido. Um reset comum de conta suspensa
        // **não** pode reativá-la: quem foi suspenso não volta pedindo
        // "esqueci minha senha" — isso é decisão de quem administra
        // (pasta 18 §3), e o enum já recusaria a transição.
        if ($usuario instanceof User && $usuario->status === UserStatus::Invited) {
            $this->status->handle($usuario, UserStatus::Active);
        }
    }
}
