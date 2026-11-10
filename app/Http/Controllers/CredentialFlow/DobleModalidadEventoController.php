<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Support\CredentialFlow\Eventos\AutorizacionDobleModalidadException;
use App\Support\CredentialFlow\Eventos\AutorizacionesDobleModalidad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Autorizar o revocar la doble certificación por modalidad de un evento. Las rutas exigen el rol super-admin (middleware) y el servicio lo vuelve a comprobar;
 * ambas acciones piden motivo y confirmación explícita. Ninguna toca participantes ni certificados.
 */
class DobleModalidadEventoController extends Controller
{
    public function __construct(private readonly AutorizacionesDobleModalidad $autorizaciones) {}

    public function autorizar(Request $request, EventoCertificacion $evento): RedirectResponse
    {
        $d = $this->validar($request);
        try {
            $this->autorizaciones->autorizar($evento, $request->user(), $d['motivo']);
        } catch (AutorizacionDobleModalidadException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'El evento quedó autorizado para la doble certificación por modalidad. No se cambió ningún participante ni certificado.');
    }

    public function revocar(Request $request, EventoCertificacion $evento): RedirectResponse
    {
        $d = $this->validar($request);
        try {
            $this->autorizaciones->revocar($evento, $request->user(), $d['motivo']);
        } catch (AutorizacionDobleModalidadException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Se revocó la doble certificación por modalidad de este evento. No se cambió ningún participante ni certificado.');
    }

    /** @return array{motivo:string} */
    private function validar(Request $request): array
    {
        return $request->validate([
            'motivo' => ['required', 'string', 'min:'.AutorizacionesDobleModalidad::MOTIVO_MIN, 'max:'.AutorizacionesDobleModalidad::MOTIVO_MAX],
            'confirmacion' => ['accepted'],
        ], [], ['motivo' => 'motivo', 'confirmacion' => 'confirmación']);
    }
}
