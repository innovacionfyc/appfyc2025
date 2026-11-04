<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Requests\CredentialFlow\StorePlantillaRequest;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Services\CredentialFlow\PlantillaService;
use App\Support\CredentialFlow\LogSeguro;
use App\Support\CredentialFlow\Plantillas\ArchivoPlantillas;
use App\Support\CredentialFlow\Plantillas\ImagenInvalidaException;
use App\Support\CredentialFlow\Plantillas\ProteccionDiseno;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PlantillaController extends Controller
{
    public function __construct(private readonly PlantillaService $plantillas) {}

    public function index(Request $request): Response
    {
        // La lista principal (y las opciones de variante QA) muestran solo las VIGENTES; las archivadas van aparte, solo para consulta y restauración.
        $plantillas = Plantilla::query()
            ->vigentes()
            ->latest()
            ->get(['id', 'nombre', 'descripcion', 'nombre_archivo_original', 'diseno', 'created_at', ...(Schema::hasColumn('cf_plantillas', 'es_plantilla_qa') ? ['es_plantilla_qa', 'variante_qa_id'] : [])])
            ->map(fn (Plantilla $p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                // Plantilla de pruebas internas (QA): designación EXPLÍCITA; solo se puede designar si su diseño lleva, como texto visible, la advertencia.
                'es_plantilla_qa' => (bool) ($p->es_plantilla_qa ?? false),
                'apta_qa' => ServicioQa::tieneAdvertenciaVisible($p),
                'variante_qa_id' => $p->variante_qa_id ?? null,
                // Ya es oficial (bases o variante propia): no se puede designar como QA (el servicio también lo exige).
                'uso_oficial' => ServicioQa::usoOficial($p),
                // En uso (emisiones o varias bases): el diseño se consulta, pero se edita una copia (el servidor lo exige al guardar).
                'diseno_protegido' => ProteccionDiseno::protegida($p),
                'descripcion' => $p->descripcion,
                'nombre_archivo_original' => $p->nombre_archivo_original,
                'elementos' => count($p->diseno['elements'] ?? []),
                'created_at' => $p->created_at?->toIso8601String(),
            ]);

        $eliminadas = Plantilla::onlyTrashed()
            ->latest('deleted_at')
            ->limit(50)
            ->get(['id', 'nombre', 'deleted_at'])
            ->map(fn (Plantilla $p) => ['id' => $p->id, 'nombre' => $p->nombre, 'eliminada_at' => $p->deleted_at?->toIso8601String()]);

        $archivadas = ! ArchivoPlantillas::disponible() ? collect() : Plantilla::query()
            ->whereNotNull('archivada_at')
            ->with('sucesora:id,nombre')
            ->latest('archivada_at')
            ->get()
            ->map(fn (Plantilla $p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'es_plantilla_qa' => (bool) ($p->es_plantilla_qa ?? false),
                'archivada_at' => $p->archivada_at?->toIso8601String(),
                'motivo' => $p->motivo_archivo,
                'sucesora' => $p->sucesora ? ['id' => $p->sucesora->id, 'nombre' => $p->sucesora->nombre] : null,
                'bases' => $p->lotes()->count(),
                'emisiones' => $p->emisiones()->count(),
            ]);

        return Inertia::render('CredentialFlow/Plantillas', [
            'archivadas' => $archivadas,
            // Solo el super-admin ve (y puede usar) archivar y restaurar; las rutas y el servicio también lo exigen.
            'puede_archivar' => ArchivoPlantillas::disponible() && ArchivoPlantillas::puedeArchivar($request->user()),
            'eliminadas' => $eliminadas,
            'plantillas' => $plantillas,
            'stats' => ['total' => $plantillas->count()],
        ]);
    }

    public function store(StorePlantillaRequest $request)
    {
        try {
            $plantilla = $this->plantillas->crear(
                $request->validated('nombre'),
                $request->validated('descripcion'),
                $request->file('pdf'), // PDF, PNG o JPG (el nombre del campo se conserva por compatibilidad)
            );
        } catch (ImagenInvalidaException $e) {
            // La imagen no se pudo usar: no se creó nada. El mensaje ya está escrito para el usuario.
            return back()->withErrors(['pdf' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Credential Flow: error al crear la plantilla: '.LogSeguro::resumen($e));

            return back()->withErrors(['general' => 'Ocurrió un error inesperado al crear la plantilla. Inténtalo de nuevo.']);
        }

        Movimiento::registrar(
            tipo: 'registro',
            modulo: 'credential-flow',
            descripcion: "Se creó la plantilla de Credential Flow: {$plantilla->nombre}"
        );

        return to_route('credential-flow.plantillas.index')
            ->with('success', "La plantilla \"{$plantilla->nombre}\" se creó correctamente.");
    }

    public function destroy(Plantilla $plantilla)
    {
        // Con cualquier emisión (aunque esté revocada o su lote eliminado) la plantilla y su PDF base se conservan:
        // son parte de la evidencia de esa emisión.
        if ($plantilla->emisiones()->exists()) {
            return to_route('credential-flow.plantillas.index')
                ->with('error', "La plantilla \"{$plantilla->nombre}\" tiene certificados emitidos y no se puede eliminar: forma parte del historial.");
        }

        // Con lotes vigentes la plantilla (y su PDF base) siguen haciendo falta para generar credenciales.
        if ($plantilla->lotes()->exists()) {
            return to_route('credential-flow.plantillas.index')
                ->with('error', "La plantilla \"{$plantilla->nombre}\" tiene bases de participantes y no se puede eliminar. Elimina primero esas bases.");
        }

        $nombre = $plantilla->nombre;
        $archivoBorrado = $this->plantillas->eliminar($plantilla);

        Movimiento::registrar(
            tipo: 'eliminacion',
            modulo: 'credential-flow',
            descripcion: "Se eliminó la plantilla de Credential Flow: {$nombre}"
        );

        if (! $archivoBorrado) {
            return to_route('credential-flow.plantillas.index')
                ->with('error', "La plantilla \"{$nombre}\" se eliminó, pero su archivo PDF no pudo borrarse del almacenamiento. Avisa al equipo técnico.");
        }

        return to_route('credential-flow.plantillas.index')
            ->with('success', "La plantilla \"{$nombre}\" se eliminó correctamente.");
    }
}
