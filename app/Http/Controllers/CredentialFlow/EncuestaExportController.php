<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Support\CredentialFlow\Encuestas\ExportacionExcedida;
use App\Support\CredentialFlow\Encuestas\ExportadorEncuestas;
use App\Support\CredentialFlow\Encuestas\InformeEncuestas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descargas XLSX y CSV de los informes de encuestas. Reflejan EXACTAMENTE los filtros de la URL, se escriben en streaming (sin cargar el resultado en memoria) y
 * solo contienen agregados. Cada descarga lleva su propio límite de uso (ver rutas).
 */
class EncuestaExportController extends Controller
{
    private const CABECERAS = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'];

    public function __construct(private readonly ExportadorEncuestas $exportador) {}

    public function xlsx(Request $request): StreamedResponse|RedirectResponse
    {
        $f = InformeEncuestas::filtros($request->query());
        try {
            $this->exportador->validar($f);
        } catch (ExportacionExcedida $e) {
            return back()->withErrors(['exportacion' => $e->getMessage()]);
        }

        return response()->streamDownload(fn () => $this->exportador->xlsx($f, 'php://output'), $this->nombre('xlsx'),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'] + self::CABECERAS);
    }

    public function csv(Request $request, string $conjunto = 'distribucion'): StreamedResponse|RedirectResponse
    {
        abort_unless(in_array($conjunto, ExportadorEncuestas::CONJUNTOS_CSV, true), 404);
        $f = InformeEncuestas::filtros($request->query());
        try {
            $this->exportador->validar($f);
        } catch (ExportacionExcedida $e) {
            return back()->withErrors(['exportacion' => $e->getMessage()]);
        }

        return response()->streamDownload(fn () => $this->exportador->csv($f, $conjunto, 'php://output'), $this->nombre('csv', $conjunto),
            ['Content-Type' => 'text/csv; charset=UTF-8'] + self::CABECERAS);
    }

    private function nombre(string $extension, ?string $conjunto = null): string
    {
        return 'informe_encuestas'.($conjunto ? '_'.$conjunto : '').'_'.now()->format('Ymd_Hi').'.'.$extension;
    }
}
