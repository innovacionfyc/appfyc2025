<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;

/**
 * Inicia la sesión PÚBLICA del portal de certificados con su propia cookie (`cf_portal_session`), limitada a la ruta del portal y de
 * corta vida. Así no comparte cookie ni sesión con la del administrador: cerrar la sesión del portal no cierra la del admin y una
 * sesión del portal nunca llega a las rutas administrativas (la cookie ni siquiera viaja a ellas).
 *
 * Delega en StartSession (no lo extiende a propósito: `withoutMiddleware(StartSession::class)` excluye también a sus subclases).
 * Se registra justo antes de StartSession en la lista de prioridad (bootstrap/app.php) para que ShareErrorsFromSession y el CSRF
 * sigan viéndolo detrás.
 */
class IniciarSesionPortal
{
    public const COOKIE = 'cf_portal_session';

    public function __construct(private readonly SessionManager $manager) {}

    public function handle($request, Closure $next)
    {
        $original = config('session');
        config([
            'session.cookie' => self::COOKIE,
            'session.path' => '/certificados',
            'session.lifetime' => (int) config('credential_flow.portal.sesion_inactividad_minutos'),
            'session.expire_on_close' => true,
            'session.same_site' => 'lax',
            'session.http_only' => true,
        ]);

        try {
            return (new StartSession($this->manager))->handle($request, $next);
        } finally {
            config(['session' => $original]);
        }
    }
}
