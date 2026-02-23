<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class VerificarRol
{

    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $usuario = Auth::user();

        $usuario->loadMissing(['perfilOrganizador.rol', 'perfilConferencista']);

        if (in_array('conferencista', $roles) && $usuario->perfilConferencista) {
            return $next($request);
        }

        if ($usuario->perfilOrganizador && $usuario->perfilOrganizador->rol) {
            $slugRol = $usuario->perfilOrganizador->rol->slug;

            if ($slugRol === 'super-admin') {
                return $next($request);
            }

            if (in_array($slugRol, $roles)) {
                return $next($request);
            }
        }

        abort(403, 'Acceso denegado. No tienes los permisos necesarios para ver esta sección.');
    }
}