<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Modules\Identity\Http\Middleware\EnsurePasswordIsChanged;
use App\Modules\Identity\Listeners\RecordPermissionDenied;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'conta.ativa' => EnsureAccountIsActive::class,
            'senha.trocada' => EnsurePasswordIsChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // `permission.denied` na trilha de segurança (pasta 26 §2).
        //
        // Fica no `render` e não no `report` porque o Laravel lista
        // AuthorizationException entre as que não se reportam — um
        // `reportable` simplesmente nunca rodaria. O `render`, sim: é ele
        // que transforma a exceção no 403 que o usuário vê.
        //
        // Devolver `null` deixa o tratamento seguir para o renderizador
        // padrão. Registrar não deve mudar a resposta.
        //
        // **Não é `AuthorizationException`**, embora seja ela que o
        // `$this->authorize()` lança: o `prepareException` do handler roda
        // ANTES dos callbacks de render e a converte em
        // `AccessDeniedHttpException`. Um callback tipado com a original
        // nunca casaria — e falharia em silêncio, sem erro algum.
        // A original continua acessível por `getPrevious()`.
        //
        // Duas inscrições, uma por classe, e não um `A|B` num só callback:
        // o Laravel descobre a qual exceção o callback pertence lendo o
        // tipo do primeiro parâmetro com `Reflector::getParameterClassName`,
        // que devolve **null** para tipo de união — mesmo silêncio.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            app(RecordPermissionDenied::class)->handle($e, $request);

            return null;
        });

        // A do spatie chega pelo middleware `permission:`/`role:`, caminho
        // diferente do `$this->authorize()` das Policies. Ela já estende
        // HttpException, então o `prepareException` a deixa passar intacta.
        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            app(RecordPermissionDenied::class)->handle($e, $request);

            return null;
        });
    })->create();
