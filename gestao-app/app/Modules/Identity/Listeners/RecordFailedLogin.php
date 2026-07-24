<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RecordSecurityEvent;
use Illuminate\Auth\Events\Failed;

/**
 * Entrada recusada → trilha de segurança (pasta 26 §2).
 *
 * O evento mais importante do canal: é a partir dele que se enxerga
 * tentativa de força bruta, e o índice `(ip_address, created_at)` da
 * tabela existe para a pergunta que ele responde.
 */
class RecordFailedLogin
{
    public function __construct(private readonly RecordSecurityEvent $trilha) {}

    public function handle(Failed $event): void
    {
        $usuario = $event->user instanceof User ? $event->user : null;

        $this->trilha->record(
            SecurityEventType::LoginFailed,
            // Nulo quando o e-mail digitado não corresponde a conta
            // alguma. É justamente o caso que a coluna `identifier`
            // existe para não perder.
            sobre: $usuario,
            identifier: $this->identificadorDigitado($event),
            context: [
                'guard' => $event->guard,
                // Distingue "senha errada de conta que existe" de
                // "e-mail que não existe" sem precisar consultar a
                // tabela de usuários no momento da leitura da trilha.
                'conta_existe' => $usuario !== null,
            ],
            // Ninguém está autenticado numa falha de login. Explícito
            // para que o serviço não tente adivinhar um ator.
            ator: null,
        );
    }

    /**
     * Só o identificador, nunca as credenciais inteiras.
     *
     * `$event->credentials` traz a **senha digitada** junto. Gravar esse
     * array em `context` colocaria senha em texto puro numa tabela que
     * várias pessoas leem com `audit.view` — transformaria a trilha de
     * segurança em vazamento de credencial.
     */
    private function identificadorDigitado(Failed $event): ?string
    {
        foreach (['email', 'username', 'name'] as $campo) {
            $valor = $event->credentials[$campo] ?? null;

            if (is_string($valor) && $valor !== '') {
                return mb_substr($valor, 0, 255);
            }
        }

        return null;
    }
}
