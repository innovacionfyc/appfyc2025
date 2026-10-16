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

            return $request->session()->has('aviso') ? $r : $r->with('aviso', 'La sesión finalizó. Ingresar nuevamente para consultar los certificados.');
        }
        // Un solo punto de autorización: los controladores usan SOLO estos atributos (nada llega del cliente). `grupos`: null = documento completo
        // (flujo histórico); lista = scope (un grupo histórico es la lista de uno). Una lista vacía nunca existe: `perteneceA` la deniega de todos modos.
        $request->attributes->set('cf_portal_documento', $contexto['documento']);
        $request->attributes->set('cf_portal_grupos', $contexto['grupos']);
        // Instante en que se autenticó la sesión: un vínculo de identidad creado DESPUÉS no se hereda en esta sesión.
        $request->attributes->set('cf_portal_desde', (int) ($request->session()->get(SesionPortal::CLAVE)['authenticated_at'] ?? time()));

        return $next($request);
    }
}
