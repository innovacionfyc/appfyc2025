<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\CasosEspeciales;
use App\Support\CredentialFlow\Conciliaciones\EvidenciaExterna;
use App\Support\CredentialFlow\Conciliaciones\GestionCaso;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Conciliaciones\ResolucionPlantillas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Casos especiales que NO se resuelven automáticamente (Fase 10B-3C-4): registrar o invalidar evidencia EXTERNA y reabrir un caso en soporte cuando llegó evidencia nueva.
 * Solo POST, solo super-admin y admin (grupo de rutas), con CSRF. Ninguna de estas acciones concede acceso al portal ni cambia el gate: solo escriben en el log del caso.
 */
class CasoEspecialController extends Controller
{
    public function __construct(private readonly EvidenciaExterna $evidencia, private readonly GestionCaso $gestion, private readonly CasosEspeciales $especiales) {}

    public function registrarEvidencia(Request $request, Conciliacion $caso): RedirectResponse
    {
        $datos = $request->validate([
            'fuente' => ['required', 'string', Rule::in(array_keys(EvidenciaExterna::FUENTES))],
            'resumen' => ['required', 'string', 'min:'.EvidenciaExterna::RESUMEN_MIN, 'max:'.EvidenciaExterna::RESUMEN_MAX],
        ], [
            'fuente.*' => 'Elige la fuente de la evidencia.', 'resumen.required' => 'Describe la evidencia.',
            'resumen.min' => 'El resumen debe tener al menos '.EvidenciaExterna::RESUMEN_MIN.' caracteres.', 'resumen.max' => 'El resumen no puede superar los '.EvidenciaExterna::RESUMEN_MAX.' caracteres.',
        ]);

        return $this->ejecutar($caso, fn () => $this->evidencia->registrar($caso->id, (int) Auth::id(), $datos['fuente'], $datos['resumen']), 'La evidencia quedó registrada. No cambia el acceso al portal ni el estado del caso.');
    }

    public function invalidarEvidencia(Request $request, Conciliacion $caso, int $evento): RedirectResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:'.ResolucionPlantillas::MOTIVO_MIN, 'max:'.ResolucionPlantillas::MOTIVO_MAX]], ['motivo.*' => 'Explica el motivo de la invalidación.']);

        return $this->ejecutar($caso, fn () => $this->evidencia->invalidar($caso->id, (int) Auth::id(), $evento, $datos['motivo']), 'La evidencia quedó invalidada. Se conserva en el historial.');
    }

    /** Reabre un caso en soporte SOLO si llegó evidencia externa vigente después de la transición a soporte (nueva evidencia). */
    public function reabrir(Request $request, Conciliacion $caso): RedirectResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:'.ResolucionPlantillas::MOTIVO_MIN, 'max:'.ResolucionPlantillas::MOTIVO_MAX]], ['motivo.*' => 'Explica el motivo de la reapertura.']);

        return $this->ejecutar($caso, function () use ($caso, $datos) {
            if (! $this->especiales->puedeReabrir($caso)) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::NO_REVERSIBLE, 'Este caso solo se reabre cuando llega evidencia externa nueva después de marcarlo como soporte.');
            }
            $this->gestion->reabrir($caso->id, (int) Auth::id(), $datos['motivo']);
        }, 'El caso volvió a estar abierto. Se conserva el historial del soporte.');
    }

    private function ejecutar(Conciliacion $caso, \Closure $accion, string $exito): RedirectResponse
    {
        $volver = redirect()->route('credential-flow.historico.casos.show', $caso->id);
        try {
            $accion();
        } catch (ResolucionNoPermitida $e) {
            return $volver->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Fallo inesperado en un caso especial.', ['conciliacion_id' => $caso->id, 'clase' => $e::class]);

            return $volver->with('error', 'No se pudo completar la acción. No se cambió nada.');
        }

        return $volver->with('success', $exito);
    }
}
