<?php

namespace App\Http\Middleware;

use App\Support\CredentialFlow\Portal\SesionPortal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Exige una sesión PÚBLICA vigente (OTP validado). No es autenticación de Laravel: no hay usuario ni roles. */
class PortalAutenticado
{
    public function handle(Request $request, Closure $next): Response
    {
        $contexto = SesionPortal::contexto($request->session());
        if ($contexto === null) {
            // Si la sesión se invalidó por un cambio de identidad, `SesionPortal` ya dejó su mensaje genérico: no se pisa.
            $r = redirect()->route('portal.inicio');

            return $request->session()->has('aviso') ? $r : $r->with('aviso', 'Tu sesión terminó. Vuelve a ingresar para consultar tus certificados.');
        }
        // Un solo punto de autorización: los controladores usan SOLO estos atributos (nada llega del cliente). `grupos`: null = documento completo
        // (flujo histórico); lista = scope (un grupo histórico es la lista de uno). Una lista vacía nunca existe: `perteneceA` la deniega de todos modos.
        $request->attributes->set('cf_portal_documento', $contexto['documento']);
        $request->attributes->set('cf_portal_grupos', $contexto['grupos']);

        return $next($request);
    }
}
