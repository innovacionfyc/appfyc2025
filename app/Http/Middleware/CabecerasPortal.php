<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Cabeceras del portal público: las de la verificación pública (no-store, noindex, no-referrer, CSP estricta) pero permitiendo enviar sus formularios al mismo origen. */
class CabecerasPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        CabecerasVerificacionPublica::nonce($request);

        return self::aplicar($next($request), CabecerasVerificacionPublica::nonce($request));
    }

    public static function aplicar(Response $response, string $nonce): Response
    {
        CabecerasVerificacionPublica::aplicar($response, $nonce);
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'self' 'nonce-{$nonce}'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

        return $response;
    }
}
