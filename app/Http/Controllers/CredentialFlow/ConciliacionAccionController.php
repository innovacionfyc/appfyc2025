<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Requests\CredentialFlow\AccionPlantillaRequest;
use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\ConsolidacionCodigo;
use App\Support\CredentialFlow\Conciliaciones\ConsolidacionVariantes;
use App\Support\CredentialFlow\Conciliaciones\GestionCaso;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Conciliaciones\ResolucionPlantillas;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Acciones de resolución de casos de conciliación: PLANTILLAS (10B-1) y consolidación de la diferencia de CÓDIGO (10B-2A). Solo POST, solo
 * super-admin y admin (grupo de rutas), con CSRF, motivo obligatorio y confirmación explícita. Los rechazos esperados vuelven a la pantalla
 * del caso con un mensaje humano (sin rutas, trazas ni datos personales); un error inesperado se registra sin datos y se informa de forma
 * genérica.
 */
class ConciliacionAccionController extends Controller
{
    public function __construct(
        private readonly ResolucionPlantillas $resolucion,
        private readonly ConsolidacionCodigo $consolidacion,
        private readonly ConsolidacionVariantes $variantes,
        private readonly GestionCaso $gestion,
    ) {}

    public function aprobarCandidata(AccionPlantillaRequest $request, Conciliacion $caso): RedirectResponse
    {
        return $this->ejecutar($caso, fn () => $this->resolucion->aprobarCandidata($caso->id, (int) Auth::id(), $request->motivo()), fn ($r) => $this->plantillas('La imagen candidata se aprobó y se asoció.', $r));
    }

    public function confirmarRenderizable(AccionPlantillaRequest $request, Conciliacion $caso): RedirectResponse
    {
        return $this->ejecutar($caso, fn () => $this->resolucion->confirmarRenderizable($caso->id, (int) Auth::id(), $request->motivo()), fn ($r) => $this->plantillas('El contenido se confirmó como renderizable.', $r));
    }

    public function aportarPlantilla(AccionPlantillaRequest $request, Conciliacion $caso): RedirectResponse
    {
        $ruta = $request->file('archivo')?->getRealPath();

        return $this->ejecutar($caso, fn () => $this->resolucion->aportarPlantilla($caso->id, (int) Auth::id(), $request->motivo(), (string) $ruta), fn ($r) => $this->plantillas('La plantilla se aportó y se asoció.', $r));
    }

    public function consolidarCodigo(AccionPlantillaRequest $request, Conciliacion $caso): RedirectResponse
    {
        return $this->ejecutar($caso, fn () => $this->consolidacion->consolidar($caso->id, (int) Auth::id(), $request->motivo()),
            fn ($r) => 'Las variantes se consolidaron: una quedó como canónica y '.$r['consolidadas'].' como duplicado consolidado. No se creó ni se copió ningún código.');
    }

    public function consolidarVariantes(AccionPlantillaRequest $request, Conciliacion $caso): RedirectResponse
    {
        return $this->ejecutar($caso, fn () => $this->variantes->consolidar($caso->id, (int) Auth::id(), $request->motivo(), ConsolidacionVariantes::MODO_CORREO),
            fn ($r) => 'Las variantes se consolidaron: una quedó como canónica y '.$r['consolidadas'].' como duplicado consolidado. No se tocaron los correos ni se creó ningún código.');
    }

    public function consolidarNombre(AccionPlantillaRequest $request, Conciliacion $caso): RedirectResponse
    {
        return $this->ejecutar($caso, fn () => $this->variantes->consolidar($caso->id, (int) Auth::id(), $request->motivo(), ConsolidacionVariantes::MODO_NOMBRE, (int) $request->input('canonico_id')),
            fn ($r) => 'La variación de nombre se consolidó con la variante que elegiste como canónica. No se modificó ningún nombre.');
    }

    public function requiereSoporte(AccionPlantillaRequest $request, Conciliacion $caso): RedirectResponse
    {
        return $this->ejecutar($caso, fn () => $this->gestion->marcarRequiereSoporte($caso->id, (int) Auth::id(), $request->motivo()), fn ($r) => 'El caso quedó marcado como «requiere soporte». No se modificó ningún certificado.');
    }

    public function descartar(AccionPlantillaRequest $request, Conciliacion $caso): RedirectResponse
    {
        return $this->ejecutar($caso, fn () => $this->gestion->descartar($caso->id, (int) Auth::id(), $request->motivo()), fn ($r) => 'El caso se descartó. No se borró nada y el certificado histórico sigue intacto y bloqueado.');
    }

    private function plantillas(string $exito, array $r): string
    {
        return $exito.' '.($r['certificados'] ?? 0).' certificados dejaron de estar pendientes de plantilla.';
    }

    /** @param  Closure(array):string  $exito */
    private function ejecutar(Conciliacion $caso, Closure $accion, Closure $exito): RedirectResponse
    {
        $volver = redirect()->route('credential-flow.historico.casos.show', $caso->id);
        try {
            $r = $accion();
        } catch (ResolucionNoPermitida $e) {
            return $e->codigo === ResolucionNoPermitida::ARCHIVO_INVALIDO
                ? $volver->withErrors(['archivo' => $e->getMessage()])
                : $volver->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Fallo inesperado al resolver un caso de conciliación.', ['conciliacion_id' => $caso->id, 'clase' => $e::class]);

            return $volver->with('error', 'No se pudo completar la acción. No se cambió nada.');
        }

        return $volver->with('success', $exito($r));
    }
}
