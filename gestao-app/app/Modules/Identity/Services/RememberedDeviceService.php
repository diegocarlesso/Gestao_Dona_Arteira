<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Events\DeviceRemembered;
use App\Modules\Identity\Models\RememberedDevice;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * O "lembrar deste dispositivo por 30 dias" do ADR-0021.
 *
 * Uma classe só para as três operações do mecanismo (confiar, reconhecer,
 * esquecer) em vez de um Service por verbo: elas compartilham o nome do
 * cookie, o algoritmo do hash e o prazo, e separá-las espalharia essas
 * três constantes por três arquivos que teriam de concordar. A regra da
 * pasta 05 §7 é sobre não acumular **casos de uso** num Service; aqui é um
 * mecanismo só, com um ciclo de vida só.
 *
 * **O que este mecanismo custa** (ADR-0021, consequências negativas): um
 * cookie destes, roubado, vale como entrar sem segundo fator até vencer.
 * Por isso o par selector/verifier — o banco guarda só o hash — e por isso
 * `esquecerTodos()` é chamado na troca de senha e em qualquer mexida no
 * 2FA. A mitigação é revogar, não prevenir.
 */
class RememberedDeviceService
{
    /**
     * Não é `remember_2fa`: cookie de sessão do Laravel já se chama
     * `remember_*`, e dois nomes parecidos no mesmo navegador é convite
     * para alguém apagar o errado ao depurar.
     */
    public const COOKIE = 'dispositivo_confiado';

    private const DIAS_DE_VALIDADE = 30;

    /**
     * Passa a confiar no dispositivo desta requisição.
     *
     * Devolve o cookie para quem chamou enfileirar — o Service não toca na
     * resposta HTTP, que é do controller (ADR-0015).
     */
    public function lembrar(User $usuario, Request $request): SymfonyCookie
    {
        $token = Str::random(60);
        $expiraEm = now()->addDays(self::DIAS_DE_VALIDADE);

        $dispositivo = new RememberedDevice([
            'user_id' => $usuario->getKey(),
            'device_id' => (string) Str::ulid(),
            'token_hash' => $this->hash($token),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1023, ''),
            'expires_at' => $expiraEm,
        ]);
        $dispositivo->save();

        DeviceRemembered::dispatch($usuario, $dispositivo);

        return Cookie::make(
            name: self::COOKIE,
            value: $dispositivo->device_id.'|'.$token,
            minutes: self::DIAS_DE_VALIDADE * 24 * 60,
            path: '/',
            domain: null,
            // A mesma política do cookie de sessão (ADR-0005). `secure`
            // acompanha a da sessão em vez de ser fixado em true: fixado,
            // o mecanismo simplesmente não funcionaria em http local e
            // ninguém descobriria por que — o navegador não devolve o
            // cookie e não avisa.
            secure: (bool) config('session.secure', false),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    /**
     * Este dispositivo já provou posse do segundo fator e ainda vale?
     */
    public function reconhece(Request $request, User $usuario): bool
    {
        $dispositivo = $this->doCookie($request, $usuario);

        if ($dispositivo === null) {
            return false;
        }

        // Só para a tela de dispositivos mostrar o que está em uso. NÃO
        // estende `expires_at`: os 30 dias correm da confirmação do TOTP,
        // senão um cookie roubado e usado todo dia nunca venceria.
        $dispositivo->forceFill(['last_used_at' => now()])->save();

        return true;
    }

    /**
     * Deixa de confiar em todos os dispositivos da conta.
     *
     * Chamado na troca de senha e em qualquer mudança de 2FA — um segredo
     * novo não herda a confiança concedida ao antigo.
     */
    public function esquecerTodos(User $usuario): void
    {
        $usuario->rememberedDevices()->delete();
    }

    /**
     * O cookie a enviar quando se quer apagar o do navegador.
     */
    public function cookieDeEsquecimento(): SymfonyCookie
    {
        return Cookie::forget(self::COOKIE);
    }

    private function doCookie(Request $request, User $usuario): ?RememberedDevice
    {
        $bruto = $request->cookie(self::COOKIE);

        if (! is_string($bruto) || ! str_contains($bruto, '|')) {
            return null;
        }

        [$deviceId, $token] = explode('|', $bruto, 2);

        /** @var RememberedDevice|null $dispositivo */
        $dispositivo = $usuario->rememberedDevices()
            ->valid()
            ->where('device_id', $deviceId)
            ->first();

        if ($dispositivo === null) {
            return null;
        }

        // `hash_equals` e não `===`: comparação de credencial em tempo
        // constante, para o tempo de resposta não revelar quantos
        // caracteres do token estavam certos.
        return hash_equals($dispositivo->token_hash, $this->hash($token))
            ? $dispositivo
            : null;
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
