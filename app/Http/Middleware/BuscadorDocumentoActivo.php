<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interruptor del buscador administrativo por documento (`credential_flow.buscador_documento.activo`, APAGADO por defecto). Va ANTES de la sesión y del rol:
 * con el interruptor apagado las rutas responden 404 a cualquiera, como si no existieran.
 */
class BuscadorDocumentoActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('credential_flow.buscador_documento.activo', false) === true, 404);

        return $next($request);
    }
}
