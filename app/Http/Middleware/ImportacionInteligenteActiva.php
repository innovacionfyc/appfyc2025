<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interruptor de la importación inteligente (`credential_flow.importacion_inteligente.activa`, APAGADO por defecto). Con el interruptor apagado las rutas nuevas
 * responden 404 como si no existieran, y el procedimiento de siempre (Nueva base) no cambia.
 */
class ImportacionInteligenteActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('credential_flow.importacion_inteligente.activa', false) === true, 404);

        return $next($request);
    }
}
