<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IniciarSesionPortal;
use App\Http\Middleware\PortalHabilitado;
use App\Http\Middleware\VerificarRol;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        // Portal público de certificados: su sesión propia corre en el lugar de StartSession (antes de ShareErrors/CSRF).
        $middleware->prependToPriorityList(before: StartSession::class, prepend: IniciarSesionPortal::class);
        // Interruptor maestro del portal (11C.1): corre antes que la sesión del portal, así apagado no se crea sesión ni desafío OTP ni envío.
        $middleware->prependToPriorityList(before: IniciarSesionPortal::class, prepend: PortalHabilitado::class);

        $middleware->alias([
            'rol' => VerificarRol::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            return response()->view('errors.404', [], 404);
        });
    })->create();
