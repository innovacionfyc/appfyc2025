<?php
// app/Http/Middleware/IsAdmin.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        // Verificamos si el usuario está autenticado y si su rol es 'Administrador'
        if (auth()->check() && auth()->user()->role->name === 'Administrador') {
            return $next($request); // Si es admin, dejamos que continúe.
        }

        // Si no es admin, lo redirigimos al dashboard normal.
        return redirect('/dashboard')->with('error', 'No tienes permisos para acceder a esta sección.');
    }
}