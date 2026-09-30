<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CredentialFlow\Concerns\RespondePdfDeCredencial;
use App\Http\Requests\CredentialFlow\UpdateDisenoRequest;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Generacion\DatosCredencial;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Editor visual de una plantilla: página, PDF privado y guardado del diseño. */
class PlantillaEditorController extends Controller
{
    use RespondePdfDeCredencial;

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
        return $this->respuestaPdf(
            fn () => GeneradorCredencialPdf::generar($plantilla, DatosCredencial::qa()),
            'PDF de prueba',
            ['plantilla' => $plantilla->id],
            'credencial-prueba-'.$plantilla->id.'.pdf',
        );
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
