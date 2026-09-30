<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Requests\CredentialFlow\StorePlantillaRequest;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Services\CredentialFlow\PlantillaService;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PlantillaController extends Controller
{
    public function __construct(private readonly PlantillaService $plantillas) {}

    public function index(): Response
    {
        $plantillas = Plantilla::query()
            ->latest()
            ->get(['id', 'nombre', 'descripcion', 'nombre_archivo_original', 'diseno', 'created_at'])
            ->map(fn (Plantilla $p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'descripcion' => $p->descripcion,
                'nombre_archivo_original' => $p->nombre_archivo_original,
                'elementos' => count($p->diseno['elements'] ?? []),
                'created_at' => $p->created_at?->toIso8601String(),
            ]);

        return Inertia::render('CredentialFlow/Plantillas', [
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
                $request->file('pdf'),
            );
        } catch (Throwable $e) {
            Log::error('Credential Flow: error al crear la plantilla: '.$e->getMessage());

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
        // Con lotes vigentes la plantilla (y su PDF base) siguen haciendo falta para generar credenciales.
        if ($plantilla->lotes()->exists()) {
            return to_route('credential-flow.plantillas.index')
                ->with('error', "La plantilla \"{$plantilla->nombre}\" tiene lotes de participantes y no se puede eliminar. Elimina primero esos lotes.");
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
