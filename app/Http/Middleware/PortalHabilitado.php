<?php

namespace App\Http\Middleware;

use App\Support\CredentialFlow\Portal\PortalFlag;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta única del portal público (Fase 11C.1). Con `credential_flow.portal_enabled` apagado NADA del portal funciona: ni inicio, ni solicitud o validación
 * de OTP, ni panel, ni descarga. Se ejecuta ANTES de iniciar la sesión del portal, por lo que no se crea sesión, ni desafío OTP, ni envío de correo.
 * GET/HEAD: página sencilla de «no disponible» (503, sin detalles internos). Cualquier otro método: 404 uniforme.
 * No protege `/verificar/{codigo}` (verificación pública de credenciales), que es un servicio independiente.
 */
class PortalHabilitado
{
    public function handle(Request $request, Closure $next): Response
    {
        if (PortalFlag::habilitado()) {
            return $next($request);
        }

        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            abort(404);
        }

        // Este middleware corre antes que CabecerasPortal (lista de prioridad): las cabeceras seguras se aplican aquí para que la respuesta no dependa del orden.
        $nonce = CabecerasVerificacionPublica::nonce($request);

        return CabecerasPortal::aplicar(response()->view('credential-flow.portal.no-disponible', ['nonce' => $nonce], 503)->header('Retry-After', '3600'), $nonce);
    }
}
