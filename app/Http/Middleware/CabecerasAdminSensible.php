<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de las pantallas de administración que muestran datos históricos (casos, certificados, eventos, evidencias): `Cache-Control: private, no-store` y
 * `X-Content-Type-Options: nosniff` (Fase 11A). No cambia el contenido de la respuesta, por lo que no afecta a Inertia; los PDFs y la vista previa del reemplazo, que ya
 * fijan sus propias cabeceras, quedan igual de estrictos.
 */
class CabecerasAdminSensible
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        if (! $response->headers->has('X-Content-Type-Options')) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        return $response;
    }
}
