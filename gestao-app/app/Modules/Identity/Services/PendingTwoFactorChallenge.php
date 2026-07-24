<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * O limbo entre a senha certa e a sessão aberta (ADR-0021).
 *
 * Quem acertou a senha mas ainda deve o segundo fator não está
 * autenticado — não existe `Auth::user()` para essa pessoa. O id dela fica
 * aqui, na sessão, por poucos minutos.
 *
 * Uma classe própria porque **dois** controllers mexem nesse estado (o de
 * login põe, o do desafio tira), e chave de sessão duplicada em dois
 * arquivos é a definição de coisa que vai divergir — ainda mais valendo
 * como credencial parcial.
 *
 * O prazo curto não é detalhe: sem ele, um "acertei a senha" ficaria
 * pendurado na sessão indefinidamente, e quem passasse pelo computador
 * depois só precisaria do segundo fator para entrar como outra pessoa.
 */
class PendingTwoFactorChallenge
{
    private const CHAVE_USUARIO = 'desafio_2fa.user_id';

    private const CHAVE_LEMBRAR_SESSAO = 'desafio_2fa.remember';

    private const CHAVE_EXPIRA_EM = 'desafio_2fa.expira_em';

    private const MINUTOS_DE_VALIDADE = 5;

    public function __construct(private readonly Session $sessao) {}

    public function iniciar(User $usuario, bool $lembrarSessao): void
    {
        $this->sessao->put(self::CHAVE_USUARIO, $usuario->getKey());
        $this->sessao->put(self::CHAVE_LEMBRAR_SESSAO, $lembrarSessao);
        $this->sessao->put(self::CHAVE_EXPIRA_EM, now()->addMinutes(self::MINUTOS_DE_VALIDADE)->getTimestamp());
    }

    /**
     * Quem está no meio do desafio, se ainda estiver dentro do prazo.
     */
    public function usuario(): ?User
    {
        $expiraEm = $this->sessao->get(self::CHAVE_EXPIRA_EM);

        if (! is_int($expiraEm) || $expiraEm < now()->getTimestamp()) {
            $this->encerrar();

            return null;
        }

        $id = $this->sessao->get(self::CHAVE_USUARIO);

        return is_int($id) ? User::find($id) : null;
    }

    /**
     * O "manter conectado" marcado lá no login, que só vale depois que o
     * segundo fator passar.
     */
    public function lembrarSessao(): bool
    {
        return (bool) $this->sessao->get(self::CHAVE_LEMBRAR_SESSAO, false);
    }

    public function encerrar(): void
    {
        $this->sessao->forget([
            self::CHAVE_USUARIO,
            self::CHAVE_LEMBRAR_SESSAO,
            self::CHAVE_EXPIRA_EM,
        ]);
    }
}
