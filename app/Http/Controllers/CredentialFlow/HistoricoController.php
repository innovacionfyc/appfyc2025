<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\GeneradorZip;
use App\Support\CredentialFlow\Eventos\DescargaHistorica;
use App\Support\CredentialFlow\Historico\ConsultaEncuestas;
use App\Support\CredentialFlow\Historico\ConsultaHistorica;
use App\Support\CredentialFlow\Legado\CongeladorLegado;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Histórico (evaluaciones legado) del administrador: SOLO LECTURA. Todas las rutas son GET; ninguna escribe en cf_eventos,
 * cf_plantillas_legado*, cf_certificados_legado, cf_correos, cf_descargas ni cf_migraciones_*. Mismo permiso que el resto de
 * Credential Flow (rol super-admin o admin, en el grupo de rutas).
 */
class HistoricoController extends Controller
{
    public function __construct(private readonly ConsultaHistorica $consulta) {}

    public function eventos(Request $request): Response
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'anio' => ['nullable', 'regex:/^(sin|\d{4})$/'],
            'plantilla' => ['nullable', 'in:'.implode(',', ConsultaHistorica::FILTROS_PLANTILLA_EVENTO)],
            'conciliacion' => ['nullable', 'in:'.implode(',', ConsultaHistorica::CONCILIACIONES)],
            'descargas' => ['nullable', 'in:con,sin'],
            'codigo' => ['nullable', 'in:con,sin'],
            'orden' => ['nullable', 'in:anio,nombre,certificados'],
        ]);

        return Inertia::render('CredentialFlow/Historico/Eventos', [
            'eventos' => $this->consulta->eventos($f)->withQueryString(),
            'filtros' => $f + ['q' => '', 'anio' => '', 'plantilla' => '', 'conciliacion' => '', 'descargas' => '', 'codigo' => '', 'orden' => 'anio'],
            'opciones' => $this->consulta->opcionesEventos(),
            'resumen' => $this->consulta->resumen(),
        ]);
    }

    public function evento(Request $request, EventoCertificacion $evento, DescargaHistorica $descarga): Response
    {
        abort_unless($evento->origen === EventoCertificacion::ORIGEN_LEGADO, 404);

        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'conciliacion' => ['nullable', 'in:'.implode(',', ConsultaHistorica::CONCILIACIONES)],
        ]);

        return Inertia::render('CredentialFlow/Historico/Evento', [
            'evento' => $this->consulta->evento($evento),
            'descarga' => $descarga->resumen($evento),
            'certificados' => $this->consulta->certificadosDeEvento($evento, $f)->withQueryString(),
            'filtros' => $f + ['q' => '', 'conciliacion' => ''],
        ]);
    }

    /**
     * ZIP de los PDF históricos YA congelados y elegibles del evento. Solo lectura: no genera, no congela ni asigna códigos. Si algún PDF esperado falta o
     * no coincide con su huella, no se entrega ningún ZIP (nunca incompleto como si estuviera completo).
     */
    public function zip(EventoCertificacion $evento, GeneradorZip $zip, DescargaHistorica $descarga, CongeladorLegado $congelador): BinaryFileResponse|RedirectResponse
    {
        abort_unless($evento->origen === EventoCertificacion::ORIGEN_LEGADO, 404);
        try {
            return $zip->paraEventoHistorico($evento, $descarga, $congelador);
        } catch (EmisionException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function certificado(CertificadoLegado $certificado): Response
    {
        return Inertia::render('CredentialFlow/Historico/Certificado', ['certificado' => $this->consulta->certificado($certificado)]);
    }

    /** Encuestas históricas: resumen y conteos por pregunta/opción y por evento. SOLO LECTURA y sin texto libre. */
    public function encuestas(Request $request, ConsultaEncuestas $consulta): Response
    {
        $f = $request->validate(['evento' => ['nullable', 'regex:/^(\d{1,10}|'.ConsultaEncuestas::NO_DISPONIBLE.')$/'], 'q' => ['nullable', 'string', 'max:80']]);
        $filtro = $f['evento'] ?? null;
        $evento = null;
        if ($filtro !== null && $filtro !== ConsultaEncuestas::NO_DISPONIBLE) {
            $filtro = (int) $filtro;
            $evento = $consulta->evento($filtro);
            abort_if($evento === null, 404);
        }
        $versiones = $consulta->versiones($filtro);

        return Inertia::render('CredentialFlow/Historico/Encuestas', [
            'resumen' => $consulta->resumen(),
            'versiones' => $versiones,
            'claves' => $consulta->resumenPorClave($versiones),
            'sin_pregunta' => $consulta->columnasSinPregunta(),
            'eventos' => $consulta->eventos($f['q'] ?? null),
            'evento' => $evento,
            'filtro' => $filtro === null ? 'todos' : ($filtro === ConsultaEncuestas::NO_DISPONIBLE ? ConsultaEncuestas::NO_DISPONIBLE : 'evento'),
            'filtros' => ['q' => $f['q'] ?? ''],
        ]);
    }

    public function plantillas(Request $request): Response
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'estado' => ['nullable', 'in:'.implode(',', ConsultaHistorica::FILTROS_PLANTILLA)],
            'renderizable' => ['nullable', 'in:si,no'],
        ]);

        return Inertia::render('CredentialFlow/Historico/Plantillas', [
            'plantillas' => $this->consulta->plantillas($f)->withQueryString(),
            'filtros' => $f + ['q' => '', 'estado' => '', 'renderizable' => ''],
            'conteos' => $this->consulta->conteosPlantillas(),
        ]);
    }

    public function buscar(Request $request): Response
    {
        $f = $request->validate([
            'campo' => ['nullable', 'in:nombre,documento,codigo'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);
        $campo = $f['campo'] ?? 'nombre';
        $q = trim((string) ($f['q'] ?? ''));

        return Inertia::render('CredentialFlow/Historico/Buscar', [
            'resultados' => $q === '' ? null : $this->consulta->buscarCertificados($campo, $q)?->withQueryString(),
            'filtros' => ['campo' => $campo, 'q' => $q],
        ]);
    }
}
