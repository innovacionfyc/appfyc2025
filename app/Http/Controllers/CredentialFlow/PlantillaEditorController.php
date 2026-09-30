<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Requests\CredentialFlow\UpdateDisenoRequest;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Generacion\DatosCredencial;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/** Editor visual de una plantilla: página, PDF privado y guardado del diseño. */
class PlantillaEditorController extends Controller
{
    public function show(Plantilla $plantilla): Response
    {
        return Inertia::render('CredentialFlow/Editor', [
            'plantilla' => [
                'id' => $plantilla->id,
                'nombre' => $plantilla->nombre,
                'descripcion' => $plantilla->descripcion,
                'nombre_archivo_original' => $plantilla->nombre_archivo_original,
            ],
            'diseno' => $plantilla->diseno,
            'pdfUrl' => route('credential-flow.plantillas.pdf', $plantilla, false),
            'schema' => DisenoSchema::paraEditor(),
        ]);
    }

    /**
     * Sirve el PDF base solo a un admin autenticado (la ruta lleva auth + rol). La ruta física
     * sale del id de la plantilla, y se exige que coincida con la registrada en BD: nunca se
     * usa nada que venga del navegador.
     */
    public function pdf(Plantilla $plantilla): HttpResponse
    {
        $disco = Storage::disk(Plantilla::DISCO);
        $ruta = $plantilla->rutaPdfEsperada();

        abort_unless($plantilla->archivo_pdf === $ruta && $disco->exists($ruta), 404);

        return $disco->response($ruta, 'plantilla-'.$plantilla->id.'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            // Si alguien abre el PDF directamente, no puede ejecutar contenido activo.
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], 'inline');
    }

    /**
     * PDF de prueba (QA): el diseño GUARDADO de la plantilla con el dataset QA fijo. No usa datos de
     * personas, no persiste nada y no registra Movimiento (no es una emisión). Los errores esperados
     * se devuelven como JSON 422 con un código estable y un mensaje seguro; el detalle va al log.
     */
    public function pdfPrueba(Plantilla $plantilla): HttpResponse|JsonResponse
    {
        try {
            $bytes = GeneradorCredencialPdf::generar($plantilla, DatosCredencial::qa());
        } catch (GeneracionCredencialException $e) {
            Log::warning('Credential Flow: PDF de prueba no generado', [
                'plantilla' => $plantilla->id,
                'codigo' => $e->codigo,
                'elemento' => $e->elementoId,
            ]);

            return response()->json(['error' => ['code' => $e->codigo, 'message' => $e->getMessage()]], 422);
        } catch (Throwable $e) {
            Log::error('Credential Flow: error inesperado al generar el PDF de prueba', [
                'plantilla' => $plantilla->id,
                'error' => $e::class.': '.$e->getMessage(),
            ]);

            return response()->json(['error' => [
                'code' => 'ERROR_INESPERADO',
                'message' => 'No se pudo generar el PDF de prueba. Inténtalo de nuevo; si continúa, avisa al equipo técnico.',
            ]], 500);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="credencial-prueba-'.$plantilla->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(UpdateDisenoRequest $request, Plantilla $plantilla)
    {
        $plantilla->update([
            'diseno' => $request->diseno(),
            'schema_version' => DisenoSchema::VERSION,
        ]);

        Movimiento::registrar(
            tipo: 'actualizacion',
            modulo: 'credential-flow',
            descripcion: "Se actualizó el diseño de la plantilla de Credential Flow: {$plantilla->nombre}"
        );

        return to_route('credential-flow.plantillas.editor', $plantilla)
            ->with('success', 'Diseño guardado correctamente.');
    }
}
