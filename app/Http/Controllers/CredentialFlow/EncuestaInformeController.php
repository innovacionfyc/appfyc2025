<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Support\CredentialFlow\Encuestas\InformeEncuestas;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resultados e informes de encuestas dentro de Credential Flow (SOLO LECTURA: únicamente rutas GET). Históricas y modernas, de cualquier versión, agrupadas
 * por evento real. Nunca devuelve texto libre individual, documentos, correos ni identificadores personales (los comentarios tienen su propio controlador).
 */
class EncuestaInformeController extends Controller
{
    public function __construct(private readonly InformeEncuestas $informe) {}

    public function resumen(Request $request): Response
    {
        $f = InformeEncuestas::filtros($request->query());

        return Inertia::render('CredentialFlow/Encuestas/Resumen', ['filtros' => $f, 'opciones' => $this->informe->opciones(), 'resumen' => $this->informe->resumen($f)]);
    }

    public function eventos(Request $request): Response
    {
        $f = InformeEncuestas::filtros($request->query());
        $eventos = $this->informe->eventos($f, max(1, (int) $request->query('page', 1)))->withPath(route('credential-flow.encuestas.eventos'))->appends($request->query());

        return Inertia::render('CredentialFlow/Encuestas/Eventos', [
            'filtros' => $f, 'opciones' => $this->informe->opciones(), 'eventos' => $eventos,
            'sin_evento' => $this->informe->resumen(array_replace($f, ['evento' => InformeEncuestas::SIN_EVENTO, 'q' => null, 'anio' => null, 'evento_desde' => null, 'evento_hasta' => null]))['respuestas'],
        ]);
    }

    public function evento(Request $request, string $evento): Response
    {
        $f = InformeEncuestas::filtros($request->query());
        // El evento queda fijo por la ruta: los filtros propios del evento (nombre, año, fechas del evento) no aplican aquí.
        $f = array_replace($f, ['evento' => $evento === InformeEncuestas::SIN_EVENTO ? InformeEncuestas::SIN_EVENTO : (int) $evento, 'q' => null, 'anio' => null, 'evento_desde' => null, 'evento_hasta' => null]);
        $info = $this->informe->evento($f['evento']);
        abort_if($info === null, 404);

        return Inertia::render('CredentialFlow/Encuestas/Evento', [
            'filtros' => $f, 'opciones' => $this->informe->opciones(), 'evento' => $info, 'resumen' => $this->informe->resumen($f), 'versiones' => $this->informe->resultados($f),
            'puede_comentarios' => $request->user() !== null && $request->user()->perfilOrganizador?->rol?->slug === 'super-admin',
        ]);
    }

    public function informes(Request $request): Response
    {
        $f = InformeEncuestas::filtros($request->query());

        return Inertia::render('CredentialFlow/Encuestas/Informes', [
            'filtros' => $f, 'opciones' => $this->informe->opciones(), 'resumen' => $this->informe->resumen($f), 'filas_distribucion' => $this->informe->contarFilasDistribucion($f),
            'limite_filas' => InformeEncuestas::MAX_FILAS_EXPORTACION,
        ]);
    }
}
