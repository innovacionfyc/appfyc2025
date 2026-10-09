<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Requests\CredentialFlow\ReemplazoRequest;
use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Reemplazo\ConsultaReemplazo;
use App\Support\CredentialFlow\Reemplazo\CreadorPlantillaReemplazo;
use App\Support\CredentialFlow\Reemplazo\ReemplazoHistorico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Histórico → Caso → «Emitir certificado corregido» (Fase 10B-2B-2B). El controlador NO reimplementa nada: la preparación del clon es de
 * `CreadorPlantillaReemplazo` y la vista previa y la emisión (locks, lote, participante, PDF, hash, código, enlace, auditoría) son de
 * `ReemplazoHistorico`. Aquí solo hay permisos (en el grupo de rutas), validación, la huella de FRESCURA de la vista previa y los mensajes.
 *
 * La vista previa contiene datos personales (es el certificado): solo administración, `no-store`, sin URL permanente (se devuelve en la respuesta
 * del POST y la pantalla la muestra desde memoria). No persiste nada: ni emisión, ni participante, ni lote, ni código, ni archivo.
 */
class ReemplazoController extends Controller
{
    /** Clave de sesión con la huella de la última vista previa generada para un caso (la emisión exige que siga vigente). */
    private const SESION = 'cf_reemplazo_preview_';

    public function __construct(
        private readonly ReemplazoHistorico $servicio,
        private readonly ConsultaReemplazo $consulta,
        private readonly CreadorPlantillaReemplazo $creador,
    ) {}

    public function show(Request $request, Conciliacion $caso): Response|RedirectResponse
    {
        $e = $this->servicio->elegible($caso);
        if (! $e['elegible']) {
            return redirect()->route('credential-flow.historico.casos.show', $caso->id)->with('error', $e['motivo']);
        }

        $respuesta = Inertia::render('CredentialFlow/Historico/Reemplazo', ['datos' => $this->consulta->pantalla($caso)])->toResponse($request);
        $respuesta->headers->set('Cache-Control', 'no-store, private');

        return $respuesta;
    }

    /** Prepara (o reutiliza) el clon moderno de la imagen histórica del certificado. Idempotente. */
    public function prepararClon(Conciliacion $caso): RedirectResponse
    {
        $volver = redirect()->route('credential-flow.historico.casos.reemplazo', $caso->id);
        try {
            $this->exigirElegible($caso);
            $cert = CertificadoLegado::query()->findOrFail($this->servicio->certificadoDelCaso($caso)->id);
            $r = $this->creador->paraCertificado($cert);
        } catch (Throwable $e) {
            return $volver->with('error', $this->mensaje($e, $caso));
        }

        return $volver->with('success', $r['creada'] ? 'La plantilla moderna quedó preparada. Revisa su diseño antes de emitir.' : 'La plantilla moderna ya estaba preparada: se reutiliza.');
    }

    /** Confirma el diseño ACTUAL del clon (después de revisarlo en el editor). Solo el clon de este caso. */
    public function confirmarDiseno(Request $request, Conciliacion $caso): RedirectResponse
    {
        $volver = redirect()->route('credential-flow.historico.casos.reemplazo', $caso->id);
        $datos = $request->validate(['plantilla_id' => ['required', 'integer', 'min:1']], ['plantilla_id.*' => 'Elige la plantilla moderna.']);
        try {
            $this->exigirElegible($caso);
            $cert = CertificadoLegado::query()->findOrFail($this->servicio->certificadoDelCaso($caso)->id);
            $clon = CreadorPlantillaReemplazo::existentePara($cert);
            // Nunca una plantilla arbitraria: solo el clon de la imagen histórica de ESTE certificado.
            if ($clon === null || (int) $clon->id !== (int) $datos['plantilla_id']) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::PLANTILLA_INVALIDA, 'Esa plantilla no corresponde a este caso.');
            }
            CreadorPlantillaReemplazo::confirmarDiseno($clon, (int) $request->user()->id);
        } catch (Throwable $e) {
            return $volver->with('error', $this->mensaje($e, $caso));
        }

        return $volver->with('success', 'Diseño confirmado para emitir reemplazos. Si lo vuelves a editar, deberás confirmarlo de nuevo.');
    }

    /** Genera la vista previa (PDF) con los datos aprobados. NO persiste nada; guarda en la sesión la huella para exigir frescura al emitir. */
    public function preview(ReemplazoRequest $request, Conciliacion $caso): Response|JsonResponse
    {
        try {
            $this->exigirElegible($caso);
            $solicitud = $request->solicitud();
            $huella = $this->servicio->huellaDe($caso->id, $solicitud);
            $pdf = $this->servicio->previsualizar($caso->id, $solicitud);
        } catch (Throwable $e) {
            return response()->json(['mensaje' => $this->mensaje($e, $caso)], 422, ['Cache-Control' => 'no-store, private']);
        }

        $request->session()->put(self::SESION.$caso->id, $huella);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="vista-previa.pdf"', 'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff', 'X-Huella-Preview' => $huella,
        ]);
    }

    /** Emite el reemplazo: solo si la vista previa vigente corresponde a los datos actuales. Todo lo demás es de `ReemplazoHistorico`. */
    public function emitir(ReemplazoRequest $request, Conciliacion $caso): RedirectResponse
    {
        $detalle = redirect()->route('credential-flow.historico.casos.show', $caso->id);
        $wizard = redirect()->route('credential-flow.historico.casos.reemplazo', $caso->id);
        $clave = self::SESION.$caso->id;
        $huella = (string) $request->input('huella');
        $guardada = $request->session()->get($clave);

        // Un reintento (doble clic, otra pantalla abierta) sobre un caso que ya no admite reemplazo recibe el mensaje exacto, no uno sobre la vista previa.
        $e = $this->servicio->elegible($caso);
        if (! $e['elegible']) {
            $request->session()->forget($clave);

            return $detalle->with('error', ConsultaReemplazo::mensaje(new ResolucionNoPermitida((string) $e['codigo'], (string) $e['motivo'])));
        }

        if (! is_string($guardada)) {
            return $wizard->with('error', ConsultaReemplazo::mensaje(new ResolucionNoPermitida(ResolucionNoPermitida::PREVIEW_REQUERIDO, '')));
        }
        if (! hash_equals($guardada, $huella)) {
            return $wizard->with('error', ConsultaReemplazo::mensaje(new ResolucionNoPermitida(ResolucionNoPermitida::PREVIEW_DESACTUALIZADO, '')));
        }

        try {
            $this->servicio->reemplazar($caso->id, (int) $request->user()->id, (string) $request->input('motivo'), $request->solicitud(), $huella);
        } catch (ResolucionNoPermitida $e) {
            $definitivo = in_array($e->codigo, [ResolucionNoPermitida::YA_REEMPLAZADO, ResolucionNoPermitida::CASO_YA_RESUELTO, ResolucionNoPermitida::YA_REEMPLAZADO_LOGICAMENTE, ResolucionNoPermitida::VARIANTE_NO_CANONICA], true);
            $request->session()->forget($clave);

            return ($definitivo ? $detalle : $wizard)->with('error', ConsultaReemplazo::mensaje($e));
        } catch (Throwable $e) {
            return $wizard->with('error', $this->mensaje($e, $caso));
        }

        $request->session()->forget($clave);

        return $detalle->with('success', 'Certificado corregido emitido correctamente.');
    }

    /** @throws ResolucionNoPermitida */
    private function exigirElegible(Conciliacion $caso): void
    {
        $e = $this->servicio->elegible($caso);
        if (! $e['elegible']) {
            throw new ResolucionNoPermitida((string) $e['codigo'], (string) $e['motivo']);
        }
    }

    /** Mensaje claro; los fallos inesperados se registran SIN datos personales (solo ids y clase). */
    private function mensaje(Throwable $e, Conciliacion $caso): string
    {
        if (! $e instanceof ResolucionNoPermitida) {
            Log::error('Fallo inesperado en el reemplazo de un certificado histórico.', ['conciliacion_id' => $caso->id, 'clase' => $e::class]);
        }

        return ConsultaReemplazo::mensaje($e);
    }
}
