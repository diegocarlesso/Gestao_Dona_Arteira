<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props compartilhadas por toda visita.
     *
     * Este payload viaja em **toda** navegação (pasta 06 §5), então cabe
     * aqui só o que praticamente toda tela usa. Dado volumoso vai por
     * prop de página.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $usuario = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => $this->citacao(),
            'auth' => [
                // Campos escolhidos um a um, não o model inteiro: o que
                // sai daqui viaja em toda visita e aparece no HTML de
                // cada página. Acrescentar campo aqui é decisão, não
                // consequência de ter adicionado uma coluna.
                'user' => $usuario === null ? null : [
                    'public_id' => $usuario->public_id,
                    'name' => $usuario->name,
                    'email' => $usuario->email,
                    'email_verified_at' => $usuario->email_verified_at?->toIso8601String(),
                    'status' => $usuario->status->value,
                    'must_change_password' => $usuario->must_change_password,
                ],
                // A UI esconde o que a pessoa não pode fazer; a
                // autoridade continua sendo a Policy no backend (pasta
                // 19 §1). Enviar isto evita que cada tela precise
                // perguntar ao servidor o que pode mostrar.
                'permissions' => $this->permissoesDe($usuario),
            ],
            'flash' => [
                'sucesso' => fn () => $request->session()->get('sucesso'),
                'erro' => fn () => $request->session()->get('erro'),
            ],
        ];
    }

    /**
     * A citação decorativa do layout de autenticação (starter kit).
     *
     * @return array{message: string, author: string}
     */
    private function citacao(): array
    {
        [$mensagem, $autor] = str(Inspiring::quotes()->random())->explode('-');

        return ['message' => trim($mensagem), 'author' => trim($autor)];
    }

    /**
     * @return list<string>
     */
    private function permissoesDe(?object $usuario): array
    {
        if (! $usuario instanceof User) {
            return [];
        }

        return $usuario->getAllPermissions()
            ->pluck('name')
            ->values()
            ->all();
    }
}
