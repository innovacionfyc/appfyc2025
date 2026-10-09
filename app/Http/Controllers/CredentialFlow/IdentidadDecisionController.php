<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Requests\CredentialFlow\DecisionIdentidadRequest;
use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\DecisionIdentidad;
use App\Support\CredentialFlow\Conciliaciones\CasosEspeciales;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Identidad\AutorizacionMasiva;
use App\Support\CredentialFlow\Identidad\DecisionesIdentidad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Decisiones de identidad de un caso `identidad_ambigua` (10B-3A): solo POST, solo super-admin y admin (grupo de rutas), con CSRF, motivo obligatorio y
 * confirmaciones según el tipo. Una decisión puede afectar el acceso al portal (según los interruptores de identidad). Los rechazos esperados vuelven al caso con un mensaje humano.
 */
class IdentidadDecisionController extends Controller
{
    public function __construct(private readonly DecisionesIdentidad $decisiones, private readonly AutorizacionMasiva $masiva, private readonly CasosEspeciales $especiales) {}

    public function crear(DecisionIdentidadRequest $request, Conciliacion $caso): RedirectResponse
    {
        return $this->ejecutar($caso, function () use ($request, $caso) {
            // 10B-3C-4: en un caso ESPECIAL que solo admite soporte / no resoluble (nombres realmente distintos, sin correo, residual, disputa, documento inválido) el bloqueo es por
            // defecto: ni «misma persona», ni autorizar un correo, ni «personas distintas». Un subgrupo parecido se resolverá luego con una decisión explícita, nunca automática.
            $c = $this->especiales->clasificar($caso);
            if ($c['solo_terminales'] && ! in_array((string) $request->input('tipo'), DecisionIdentidad::TERMINALES, true)) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'Este caso no puede resolverse automáticamente: solo se admite marcarlo como soporte o no resoluble, o registrar evidencia externa. No se abre acceso por parecido.');
            }
            $r = $this->decisiones->crear($caso->id, (int) Auth::id(), (string) $request->input('tipo'), trim((string) $request->input('motivo')), $request->parametros());

            if ($r['creada'] && ($r['aprobacion_pendiente'] ?? false)) {
                return 'La solicitud de autorización masiva quedó registrada. NO habilita ningún acceso hasta que un segundo administrador la apruebe.';
            }

            return $r['creada'] ? 'La decisión quedó registrada. Puede afectar el acceso de la persona a sus certificados.' : 'Esta decisión ya está registrada.';
        });
    }

    public function revocar(DecisionIdentidadRequest $request, Conciliacion $caso, DecisionIdentidad $decision): RedirectResponse
    {
        return $this->ejecutar($caso, function () use ($request, $caso, $decision) {
            // La decisión debe pertenecer a ESTE caso: nunca se revoca por un id ajeno.
            abort_unless((int) $decision->conciliacion_id === (int) $caso->id, 404);
            $this->decisiones->revocar($decision->id, (int) Auth::id(), trim((string) $request->input('motivo')));

            return 'La decisión se revocó. Se conserva su historial; revocarla puede retirar el acceso asociado.';
        });
    }

    /** Segunda aprobación (doble control, 10B-3C-3): un administrador DISTINTO del solicitante. El backend lo valida; la interfaz solo lo refleja. */
    public function aprobarMasiva(DecisionIdentidadRequest $request, Conciliacion $caso, DecisionIdentidad $decision): RedirectResponse
    {
        return $this->ejecutar($caso, function () use ($request, $caso, $decision) {
            abort_unless((int) $decision->conciliacion_id === (int) $caso->id, 404);
            $this->masiva->aprobar($decision->id, (int) Auth::id(), trim((string) $request->input('motivo')), $request->boolean('confirmo_masiva'));

            return 'La autorización masiva quedó aprobada. Podrá habilitar el acceso si el interruptor masivo está encendido.';
        });
    }

    /** Revoca solo la segunda aprobación (hacia adelante): la autorización deja de ser aplicable y las sesiones masivas mueren. */
    public function revocarAprobacion(DecisionIdentidadRequest $request, Conciliacion $caso, DecisionIdentidad $decision): RedirectResponse
    {
        return $this->ejecutar($caso, function () use ($request, $caso, $decision) {
            abort_unless((int) $decision->conciliacion_id === (int) $caso->id, 404);
            $this->masiva->revocarAprobacion($decision->id, (int) Auth::id(), trim((string) $request->input('motivo')));

            return 'La aprobación se revocó. La decisión se conserva pero ya no habilita el acceso.';
        });
    }

    private function ejecutar(Conciliacion $caso, \Closure $accion): RedirectResponse
    {
        $volver = redirect()->route('credential-flow.historico.casos.show', $caso->id);
        try {
            $mensaje = $accion();
        } catch (ResolucionNoPermitida $e) {
            return $volver->with('error', $e->getMessage());
        } catch (Throwable $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }
            Log::error('Fallo inesperado en una decisión de identidad.', ['conciliacion_id' => $caso->id, 'clase' => $e::class]);

            return $volver->with('error', 'No se pudo completar la acción. No se cambió nada.');
        }

        return $volver->with('success', $mensaje);
    }
}
