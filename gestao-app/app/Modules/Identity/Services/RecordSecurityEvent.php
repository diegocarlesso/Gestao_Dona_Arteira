<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Enums\SecurityEventType;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Grava uma entrada na trilha de segurança (pasta 26 §2.1).
 *
 * **Registrar não pode negar serviço.** É o desvio deliberado da pasta 26
 * §3, e vale a pena repetir o porquê: se a gravação falhasse dentro do
 * login, uma tabela cheia ou um índice corrompido trancaria todo mundo
 * para fora do ERP. O mecanismo de vigilância derrubaria a operação que
 * ele existe para proteger — o mesmo formato da armadilha P-16, em que o
 * log quebrava a aplicação.
 *
 * Daí a forma: síncrono (evento perdido em fila que falha é evento
 * perdido em silêncio), mas dentro de `try/catch`, com o erro indo para
 * `Log::critical`. Isso só é honesto porque o log da produção passou a
 * funcionar em 2026-07-24; antes disso o `catch` seria um buraco sem
 * fundo.
 */
class RecordSecurityEvent
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        SecurityEventType $event,
        ?User $sobre = null,
        ?string $identifier = null,
        array $context = [],
        ?User $ator = null,
    ): ?SecurityEvent {
        try {
            return SecurityEvent::create([
                'event' => $event,
                'user_id' => $sobre?->getKey(),
                'actor_id' => ($ator ?? $this->atorAtual())?->getKey(),
                'identifier' => $identifier,
                'ip_address' => $this->request->ip(),
                'user_agent' => $this->cortarUserAgent($this->request->userAgent()),
                'context' => $context === [] ? null : $context,
            ]);
        } catch (Throwable $e) {
            // `critical` e não `error`: a trilha de segurança ter parado
            // de gravar é um incidente, não um aviso. O contexto repete os
            // dados do evento para que a linha do log substitua a entrada
            // que não foi gravada.
            Log::critical('Falha ao gravar a trilha de segurança.', [
                'evento' => $event->value,
                'user_id' => $sobre?->getKey(),
                'identifier' => $identifier,
                'excecao' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Quem está autenticado, quando houver.
     *
     * Numa ação de sistema (seeder, job, comando) não há ninguém, e a
     * coluna fica nula — o que a pasta 26 §3 prevê ao aceitar `system`
     * como autor.
     */
    private function atorAtual(): ?User
    {
        $usuario = Auth::user();

        return $usuario instanceof User ? $usuario : null;
    }

    /**
     * A coluna comporta 1023 caracteres, como a da `audits`. Um
     * user-agent maior que isso derrubaria a gravação — e o `catch` acima
     * transformaria um cabeçalho esquisito em incidente registrado como
     * crítico.
     */
    private function cortarUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        return mb_substr($userAgent, 0, 1023);
    }
}
