<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Requests\CredentialFlow\StoreLoteRequest;
use App\Http\Requests\CredentialFlow\UpdateLoteRequest;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Emisiones\FlujoDeBase;
use App\Support\CredentialFlow\LogSeguro;
use App\Support\CredentialFlow\Participantes\CorreosDeParticipante;
use App\Support\CredentialFlow\Participantes\ImportacionInvalidaException;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Participantes\LectorArchivo;
use App\Support\CredentialFlow\Participantes\PlantillaExcelParticipantes;
use App\Support\CredentialFlow\Qa\AdministradorContactosQa;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/** Lotes de participantes: listado, importación (validar / confirmar), detalle, edición y eliminación. */
class LoteController extends Controller
{
    public const POR_PAGINA_LOTES = 15;

    public const POR_PAGINA_PARTICIPANTES = 25;

    public function __construct(private readonly ImportadorParticipantes $importador, private readonly ServicioQa $qa) {}

    public function index(Request $request): Response
    {
        // Filtro por estado operativo (Activas | Borradores | Archivadas | Pruebas internas). Sin el filtro se ven las «activas», que es donde están todas las bases existentes.
        $hayEstado = Schema::hasColumn('cf_lotes', 'estado_operativo');
        $estado = in_array($request->query('estado'), Lote::ESTADOS_OPERATIVOS, true) ? (string) $request->query('estado') : Lote::ACTIVA;
        $conteo = $hayEstado ? Lote::query()->selectRaw('estado_operativo, count(*) as total')->groupBy('estado_operativo')->pluck('total', 'estado_operativo') : collect();

        $lotes = Lote::query()
            ->when($hayEstado, fn ($q) => $q->where('estado_operativo', $estado))
            ->with('plantilla:id,nombre')
            // «Participantes» = los REALES; los contactos de control (QA) van aparte.
            ->withCount(['participantes as participantes_count' => fn ($q) => $q->where('es_control_qa', false), 'participantes as qa_count' => fn ($q) => $q->where('es_control_qa', true)])
            ->latest()
            ->paginate(self::POR_PAGINA_LOTES)
            ->through(fn (Lote $l) => [
                'id' => $l->id,
                'nombre' => $l->nombre,
                'plantilla' => $l->plantilla?->nombre,
                'evento' => $l->datos_comunes['evento'] ?? null,
                'fecha' => $l->datos_comunes['fecha'] ?? null,
                'participantes' => $l->participantes_count,
                'qa' => $l->qa_count,
                'estado_operativo' => $l->estado_operativo ?? Lote::ACTIVA,
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        // Bases eliminadas «normalmente» (ocultas): siguen ocupando espacio hasta que se eliminen definitivamente.
        $eliminadas = Lote::onlyTrashed()
            ->withCount(['participantes as participantes_count' => fn ($q) => $q->withTrashed()])
            ->latest('deleted_at')
            ->limit(50)
            ->get()
            ->map(fn (Lote $l) => [
                'id' => $l->id,
                'nombre' => $l->nombre,
                'participantes' => $l->participantes_count,
                'eliminada_at' => $l->deleted_at?->toIso8601String(),
            ]);

        return Inertia::render('CredentialFlow/Lotes/Index', [
            'lotes' => $lotes->withQueryString(),
            'eliminadas' => $eliminadas,
            'estado' => $estado,
            'estados' => collect(Lote::ESTADOS_OPERATIVOS)->mapWithKeys(fn (string $e) => [$e => (int) ($conteo[$e] ?? 0)])->all(),
            'estado_disponible' => $hayEstado,
        ]);
    }

    public function nuevo(Request $request): Response
    {
        $plantillas = Plantilla::query()
            ->whereNotNull('diseno')
            ->latest()
            ->get(['id', 'nombre', 'diseno'])
            ->filter(fn (Plantilla $p) => ! empty($p->diseno['elements'] ?? null))
            ->map(fn (Plantilla $p) => ['id' => $p->id, 'nombre' => $p->nombre])
            ->values();

        // Primero el evento, después sus participantes: solo eventos de Credential Flow (los históricos no reciben bases nuevas).
        $eventos = ! Schema::hasTable('cf_eventos') ? collect() : EventoCertificacion::query()
            ->where('origen', EventoCertificacion::ORIGEN_CREDENTIAL_FLOW)
            ->where('estado', EventoCertificacion::ESTADO_ACTIVO)
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'nombre', 'fecha_texto', 'anio'])
            ->map(fn (EventoCertificacion $e) => ['id' => $e->id, 'nombre' => $e->nombre, 'fecha' => $e->fecha_texto, 'anio' => $e->anio]);

        return Inertia::render('CredentialFlow/Lotes/Nuevo', [
            'plantillas' => $plantillas,
            'eventos' => $eventos,
            'evento_seleccionado' => $request->integer('evento') ?: null,
            'qa_catalogo' => config('credential_flow.qa.automatico', true) && config('credential_flow.qa.exigir_catalogo', true) ? app(AdministradorContactosQa::class)->catalogoListo() : ['listo' => true, 'motivos' => [], 'activos' => 0, 'esperados' => 0],
            'limites' => [
                'archivoMb' => LectorArchivo::ARCHIVO_MAX_BYTES / 1024 / 1024,
                'filas' => LectorArchivo::FILAS_MAX,
            ],
        ]);
    }

    /** Descarga de la plantilla Excel de participantes (columnas exactas + hoja de instrucciones). */
    public function plantillaExcel(): BinaryFileResponse
    {
        return PlantillaExcelParticipantes::respuesta();
    }

    /**
     * Con la incorporación automática de contactos QA encendida, crear una base exige el catálogo completo y verificado: si no lo está, la creación se DETIENE
     * con una advertencia (nunca se crea una base incompleta en silencio).
     */
    private function catalogoQaIncompleto(): ?JsonResponse
    {
        if (! config('credential_flow.qa.automatico', true) || ! config('credential_flow.qa.exigir_catalogo', true)) {
            return null;
        }
        $c = app(AdministradorContactosQa::class)->catalogoListo();
        if ($c['listo']) {
            return null;
        }

        return response()->json(['error' => [
            'code' => 'QA_CATALOGO_INCOMPLETO',
            'message' => 'No se puede crear la base: el catálogo de contactos QA no está listo. '.implode(' ', $c['motivos']).' Revísalo en «Contactos QA».',
        ]], 409);
    }

    /** Validar: procesa el archivo y devuelve el preview. NO toca la base de datos. */
    public function validar(StoreLoteRequest $request): JsonResponse
    {
        if ($bloqueo = $this->catalogoQaIncompleto()) {
            return $bloqueo;
        }

        try {
            $resultado = $this->importador->validar($request->file('archivo'), $request->plantilla(), $request->datosComunes());
        } catch (Throwable $e) {
            Log::error('Credential Flow: error inesperado al validar el archivo de participantes', ['error' => LogSeguro::resumen($e)]);

            return $this->errorInesperado();
        }

        return response()->json(['resultado' => $resultado->toArray()]);
    }

    /** Confirmar: vuelve a validar desde cero y, si todo es válido, importa en una transacción. */
    public function store(StoreLoteRequest $request): JsonResponse
    {
        if ($bloqueo = $this->catalogoQaIncompleto()) {
            return $bloqueo;
        }

        try {
            $lote = $this->importador->importar(
                $request->file('archivo'),
                $request->plantilla(),
                $request->nombreLote(),
                $request->descripcionLote(),
                $request->datosComunes(),
                $request->eventoElegido()?->id,
            );
        } catch (ImportacionInvalidaException $e) {
            return response()->json([
                'error' => ['code' => 'IMPORTACION_INVALIDA', 'message' => $e->getMessage()],
                'resultado' => $e->resultado->toArray(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Credential Flow: error inesperado al importar participantes', ['error' => LogSeguro::resumen($e)]);

            return $this->errorInesperado();
        }

        // Los contactos de control (QA) oficiales entran solos a cada base nueva (idempotente; sin la tabla no hace nada). Un fallo aquí nunca deshace la importación.
        $qa = null;
        if (config('credential_flow.qa.automatico', true)) {
            try {
                $qa = $this->qa->incorporar($lote, $request->user()?->id);
            } catch (Throwable $e) {
                Log::error('Credential Flow: no se pudieron incorporar los contactos QA', ['lote' => $lote->id, 'error' => LogSeguro::resumen($e)]);
            }
        }

        session()->flash('success', "La base \"{$lote->nombre}\" se creó con éxito.".($qa !== null && $qa['creados'] > 0 ? " Se incorporaron {$qa['creados']} contactos de control (QA) para las pruebas internas; no cuentan como participantes reales." : ''));

        return response()->json(['redirect' => route('credential-flow.lotes.show', $lote, false)]);
    }

    public function show(Request $request, Lote $lote): Response
    {
        $busqueda = trim((string) $request->query('q', ''));
        $like = '%'.addcslashes($busqueda, '%_\\').'%';

        $participantes = $lote->participantes()
            ->when($busqueda !== '', fn ($q) => $q->where(fn ($w) => $w->where('nombre_completo', 'like', $like)->orWhere('documento', 'like', $like)))
            ->with('emisionVigente:id,participante_id,version,codigo')
            ->withCount('emisiones')
            ->orderBy('id')
            ->paginate(self::POR_PAGINA_PARTICIPANTES, ['id', 'nombre_completo', 'documento', 'fila_origen', 'es_control_qa', ...(Schema::hasColumn('cf_participantes', 'correo') ? ['correo', 'correo_estado'] : []), 'elegibilidad', 'intensidad_horaria', 'elegibilidad_motivo', 'tipo_documento_confirmado'])
            ->withQueryString()
            // Estado DERIVADO de cf_emisiones (no hay columna de estado en el participante):
            // sin emisiones = «sin_emitir», una vigente = «emitida», solo historial = «revocada».
            ->through(fn (Participante $p) => [
                'id' => $p->id,
                'nombre_completo' => $p->nombre_completo,
                'documento' => $p->documento,
                'fila_origen' => $p->fila_origen,
                'es_control_qa' => (bool) $p->es_control_qa,
                'elegibilidad' => $p->elegibilidad ?? 'elegible',
                'intensidad_horaria' => $p->intensidad_horaria,
                'elegibilidad_motivo' => $p->elegibilidad_motivo,
                'tipo_documento_confirmado' => (bool) ($p->tipo_documento_confirmado ?? true),
                // El correo actual, tal como se guardó (varios separados por «; »). Solo lo ve el administrador, para poder corregirlo.
                'correo' => implode('; ', app(CorreosDeParticipante::class)->originalesActuales($p)),
                'emisiones_count' => $p->emisiones_count,
                'estado_emision' => $p->emisionVigente ? 'emitida' : ($p->emisiones_count > 0 ? 'revocada' : 'sin_emitir'),
                'emision' => $p->emisionVigente ? ['id' => $p->emisionVigente->id, 'version' => $p->emisionVigente->version] : null,
            ]);

        $resumen = app(EmisorLote::class)->resumen($lote);

        return Inertia::render('CredentialFlow/Lotes/Show', [
            'lote' => [
                'id' => $lote->id,
                'nombre' => $lote->nombre,
                'descripcion' => $lote->descripcion,
                'datos_comunes' => $lote->datos_comunes,
                'archivo_nombre' => $lote->archivo_nombre,
                'plantilla' => $lote->plantilla ? ['id' => $lote->plantilla->id, 'nombre' => $lote->plantilla->nombre] : null,
                'created_at' => $lote->created_at?->toIso8601String(),
                'total' => $lote->participantes()->count(),
                'evento_id' => $lote->evento_id,
                'emision' => $resumen,
                'flujo' => app(FlujoDeBase::class)->para($lote, $resumen),
            ],
            'participantes' => $participantes,
            'filtros' => ['q' => $busqueda],
        ]);
    }

    public function update(UpdateLoteRequest $request, Lote $lote)
    {
        $lote->update([
            'nombre' => $request->nombreLote(),
            'descripcion' => $request->descripcionLote(),
            'datos_comunes' => $request->datosComunes(),
        ]);

        Movimiento::registrar(
            tipo: 'actualizacion',
            modulo: 'credential-flow',
            descripcion: "Se actualizó el lote de Credential Flow «{$lote->nombre}»",
            extra: ['lote_id' => $lote->id],
        );

        return back()->with('success', 'La base se actualizó correctamente.');
    }

    /**
     * Cambia el estado OPERATIVO de una base (activa | borrador | archivada | prueba). Es solo una etiqueta para ordenar el listado: no toca participantes,
     * emisiones, PDF ni descargas, y se puede revertir. Nunca se cambia sola: lo decide el administrador, con confirmación.
     */
    public function estado(Request $request, Lote $lote): RedirectResponse
    {
        $d = $request->validate(['estado' => ['required', 'in:'.implode(',', Lote::ESTADOS_OPERATIVOS)], 'confirmo' => ['accepted']], ['confirmo.accepted' => 'Confirma el cambio de estado.']);
        if (! Schema::hasColumn('cf_lotes', 'estado_operativo')) {
            return back()->with('error', 'El estado de las bases todavía no está disponible en este entorno.');
        }
        $anterior = $lote->estado_operativo ?? Lote::ACTIVA;
        if ($anterior !== $d['estado']) {
            $lote->update(['estado_operativo' => $d['estado']]);
            Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: 'Se cambió el estado operativo de una base', extra: ['lote_id' => $lote->id, 'de' => $anterior, 'a' => $d['estado']]);
        }

        return back()->with('success', "La base «{$lote->nombre}» ahora está en «".self::ROTULOS_ESTADO[$d['estado']].'». No se modificó ningún participante ni certificado.');
    }

    private const ROTULOS_ESTADO = [Lote::ACTIVA => 'Activas', Lote::BORRADOR => 'Borradores', Lote::ARCHIVADA => 'Archivadas', Lote::PRUEBA => 'Pruebas internas'];

    /** Soft delete: los participantes no se borran; quedan ocultos porque solo se consultan a través de su lote. */
    public function destroy(Lote $lote)
    {
        // Con emisiones vigentes no se puede ocultar el lote: hay que revocarlas primero. Con solo revocadas se
        // permite (soft delete): las emisiones permanecen y el historial las resuelve con withTrashed.
        if ($lote->emisiones()->where('estado', Emision::EMITIDA)->exists()) {
            return back()->with('error', "La base \"{$lote->nombre}\" tiene certificados vigentes. Revócalos antes de eliminarla.");
        }

        $nombre = $lote->nombre;
        $lote->delete();

        Movimiento::registrar(
            tipo: 'eliminacion',
            modulo: 'credential-flow',
            descripcion: "Se eliminó el lote de Credential Flow «{$nombre}»",
            extra: ['lote_id' => $lote->id],
        );

        return to_route('credential-flow.lotes.index')->with('success', "La base \"{$nombre}\" se eliminó correctamente.");
    }

    private function errorInesperado(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'ERROR_INESPERADO',
            'message' => 'Ocurrió un error inesperado. Inténtalo de nuevo; si continúa, avisa al equipo técnico.',
        ]], 500);
    }
}
