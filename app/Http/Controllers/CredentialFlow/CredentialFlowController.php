<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CredentialFlowController extends Controller
{
    public function index(Request $request): Response
    {
        // La tarjeta del buscador por documento solo aparece con su interruptor encendido y para super-admin (las mismas condiciones de su ruta).
        $usuario = $request->user()?->loadMissing('perfilOrganizador.rol');

        return Inertia::render('CredentialFlow/Inicio', [
            'buscadorDocumento' => config('credential_flow.buscador_documento.activo', false) === true && $usuario?->perfilOrganizador?->rol?->slug === 'super-admin',
        ]);
    }
}
