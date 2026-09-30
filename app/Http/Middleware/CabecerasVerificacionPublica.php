<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de la verificación pública de Credential Flow: no indexable, sin caché (una revocación se ve al
 * instante), sin referrer y con una CSP estricta. Genera un nonce por respuesta para el <style> en línea de la
 * vista. Debe ir ANTES del limitador para que también la respuesta 429 las lleve.
 */
class CabecerasVerificacionPublica
{
    public const ATRIBUTO_NONCE = 'cf_csp_nonce';

    public function handle(Request $request, Closure $next): Response
    {
        self::nonce($request);

        /** @var Response $response */
        $response = $next($request);

        return self::aplicar($response, self::nonce($request));
    }

    /** Nonce de esta petición (se crea una sola vez; el limitador puede necesitarlo antes que este middleware). */
    public static function nonce(Request $request): string
    {
        if (! $request->attributes->has(self::ATRIBUTO_NONCE)) {
            $request->attributes->set(self::ATRIBUTO_NONCE, base64_encode(random_bytes(16)));
        }

        return $request->attributes->get(self::ATRIBUTO_NONCE);
    }

    public static function aplicar(Response $response, string $nonce): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'self' 'nonce-{$nonce}'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

        return $response;
    }
}
