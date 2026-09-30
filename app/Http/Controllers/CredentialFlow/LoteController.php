<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Requests\CredentialFlow\StoreLoteRequest;
use App\Http\Requests\CredentialFlow\UpdateLoteRequest;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Participantes\ImportacionInvalidaException;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Participantes\LectorArchivo;
use App\Support\CredentialFlow\Participantes\PlantillaExcelParticipantes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/** Lotes de participantes: listado, importación (validar / confirmar), detalle, edición y eliminación. */
class LoteController extends Controller
{
    public const POR_PAGINA_LOTES = 15;

    public const POR_PAGINA_PARTICIPANTES = 25;

    public function __construct(private readonly ImportadorParticipantes $importador) {}

    public function index(): Response
    {
        $lotes = Lote::query()
            ->with('plantilla:id,nombre')
            ->withCount('participantes')
            ->latest()
            ->paginate(self::POR_PAGINA_LOTES)
            ->through(fn (Lote $l) => [
                'id' => $l->id,
                'nombre' => $l->nombre,
                'plantilla' => $l->plantilla?->nombre,
                'evento' => $l->datos_comunes['evento'] ?? null,
                'fecha' => $l->datos_comunes['fecha'] ?? null,
                'participantes' => $l->participantes_count,
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        return Inertia::render('CredentialFlow/Lotes/Index', ['lotes' => $lotes]);
    }

    public function nuevo(): Response
    {
        $plantillas = Plantilla::query()
            ->whereNotNull('diseno')
            ->latest()
            ->get(['id', 'nombre', 'diseno'])
            ->filter(fn (Plantilla $p) => ! empty($p->diseno['elements'] ?? null))
            ->map(fn (Plantilla $p) => ['id' => $p->id, 'nombre' => $p->nombre])
            ->values();

        return Inertia::render('CredentialFlow/Lotes/Nuevo', [
            'plantillas' => $plantillas,
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

    /** Validar: procesa el archivo y devuelve el preview. NO toca la base de datos. */
    public function validar(StoreLoteRequest $request): JsonResponse
    {
        try {
            $resultado = $this->importador->validar($request->file('archivo'), $request->plantilla(), $request->datosComunes());
        } catch (Throwable $e) {
            Log::error('Credential Flow: error inesperado al validar el archivo de participantes', ['error' => $e::class.': '.$e->getMessage()]);

            return $this->errorInesperado();
        }

        return response()->json(['resultado' => $resultado->toArray()]);
    }

    /** Confirmar: vuelve a validar desde cero y, si todo es válido, importa en una transacción. */
    public function store(StoreLoteRequest $request): JsonResponse
    {
        try {
            $lote = $this->importador->importar(
                $request->file('archivo'),
                $request->plantilla(),
                $request->nombreLote(),
                $request->descripcionLote(),
                $request->datosComunes(),
            );
        } catch (ImportacionInvalidaException $e) {
            return response()->json([
                'error' => ['code' => 'IMPORTACION_INVALIDA', 'message' => $e->getMessage()],
                'resultado' => $e->resultado->toArray(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Credential Flow: error inesperado al importar participantes', ['error' => $e::class.': '.$e->getMessage()]);

            return $this->errorInesperado();
        }

        session()->flash('success', "El lote \"{$lote->nombre}\" se importó con éxito.");

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
            ->paginate(self::POR_PAGINA_PARTICIPANTES, ['id', 'nombre_completo', 'documento', 'fila_origen'])
            ->withQueryString()
            // Estado DERIVADO de cf_emisiones (no hay columna de estado en el participante):
            // sin emisiones = «sin_emitir», una vigente = «emitida», solo historial = «revocada».
            ->through(fn (Participante $p) => [
                'id' => $p->id,
                'nombre_completo' => $p->nombre_completo,
                'documento' => $p->documento,
                'fila_origen' => $p->fila_origen,
                'emisiones_count' => $p->emisiones_count,
                'estado_emision' => $p->emisionVigente ? 'emitida' : ($p->emisiones_count > 0 ? 'revocada' : 'sin_emitir'),
                'emision' => $p->emisionVigente ? ['id' => $p->emisionVigente->id, 'version' => $p->emisionVigente->version] : null,
            ]);

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
                'emision' => app(EmisorLote::class)->resumen($lote),
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

        return back()->with('success', 'El lote se actualizó correctamente.');
    }

    /** Soft delete: los participantes no se borran; quedan ocultos porque solo se consultan a través de su lote. */
    public function destroy(Lote $lote)
    {
        // Con emisiones vigentes no se puede ocultar el lote: hay que revocarlas primero. Con solo revocadas se
        // permite (soft delete): las emisiones permanecen y el historial las resuelve con withTrashed.
        if ($lote->emisiones()->where('estado', Emision::EMITIDA)->exists()) {
            return back()->with('error', "El lote \"{$lote->nombre}\" tiene credenciales emitidas vigentes. Revócalas antes de eliminarlo.");
        }

        $nombre = $lote->nombre;
        $lote->delete();

        Movimiento::registrar(
            tipo: 'eliminacion',
            modulo: 'credential-flow',
            descripcion: "Se eliminó el lote de Credential Flow «{$nombre}»",
            extra: ['lote_id' => $lote->id],
        );

        return to_route('credential-flow.lotes.index')->with('success', "El lote \"{$nombre}\" se eliminó correctamente.");
    }

    private function errorInesperado(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'ERROR_INESPERADO',
            'message' => 'Ocurrió un error inesperado. Inténtalo de nuevo; si continúa, avisa al equipo técnico.',
        ]], 500);
    }
}
