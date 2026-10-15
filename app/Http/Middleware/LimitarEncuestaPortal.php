<?php

namespace App\Http\Middleware;

use App\Http\Controllers\CredentialFlow\PortalPublicoController;
use App\Support\CredentialFlow\Portal\Hmac;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Límite PROPIO de la encuesta del portal (no reutiliza los del OTP ni el de descarga). Va DESPUÉS de `PortalAutenticado`, de modo que el documento sale de
 * la sesión ya autorizada y nunca del cliente. Tres contadores independientes por minuto:
 *   - por sesión del portal;
 *   - por identidad: HMAC del documento autorizado (no se evade abriendo otra sesión, cambiando de IP ni de agente);
 *   - por IP, solo como defensa adicional y con un tope holgado: una red compartida no bloquea a otras personas.
 * Solo cuentan las peticiones permitidas; una petición rechazada no extiende el bloqueo.
 */
class LimitarEncuestaPortal
{
    private const FICHA = 'cf_portal.limite_encuesta';

    public function handle(Request $request, Closure $next): Response
    {
        $limites = [];
        if ($request->hasSession()) {
            // Identificador propio de la sesión del portal (guardado en ella): estable aunque el id de sesión rote y no revela nada.
            $sesion = $request->session();
            $ficha = $sesion->get(self::FICHA);
            if (! is_string($ficha) || $ficha === '') {
                $sesion->put(self::FICHA, $ficha = bin2hex(random_bytes(16)));
            }
            $limites['cf-portal-enc-ses:'.$ficha] = (int) config('credential_flow.portal.limite_encuesta_por_sesion_por_minuto');
        }
        $documento = (string) $request->attributes->get('cf_portal_documento', '');
        if ($documento !== '') {
            $limites['cf-portal-enc-doc:'.Hmac::de('documento', $documento)] = (int) config('credential_flow.portal.limite_encuesta_por_identidad_por_minuto');
        }
        $limites['cf-portal-enc-ip:'.$request->ip()] = (int) config('credential_flow.portal.limite_encuesta_por_ip_por_minuto');

        foreach ($limites as $clave => $maximo) {
            if (RateLimiter::tooManyAttempts($clave, $maximo)) {
                return PortalPublicoController::limitada($request, RateLimiter::availableIn($clave));
            }
        }
        foreach (array_keys($limites) as $clave) {
            RateLimiter::hit($clave, 60);
        }

        return $next($request);
    }
}
