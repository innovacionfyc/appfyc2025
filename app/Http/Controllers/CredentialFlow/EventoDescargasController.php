<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Support\CredentialFlow\Descargas\SeguimientoDescargasEvento;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pestaña «Descargas» de un evento (moderno o histórico): SOLO LECTURA. Administradores y super-admin ven el resumen y el listado con el documento
 * ENMASCARADO; solo el super-admin ve el documento completo y puede buscar por documento exacto (se comprueba aquí, en el servidor). No registra nada.
 */
class EventoDescargasController extends Controller
{
    public const AVISO = 'Una descarga registrada indica que el portal entregó el archivo. No prueba que el titular lo haya recibido, abierto o leído, ni que '
        .'quien lo descargó sea el titular: el acceso se verifica con un código enviado a un correo asociado al documento.';

    public function __construct(private readonly SeguimientoDescargasEvento $seguimiento) {}

    public function show(Request $request, EventoCertificacion $evento): Response
    {
        $f = $request->validate([
            'tipo' => ['nullable', Rule::in(SeguimientoDescargasEvento::TIPOS)],
            'clase' => ['nullable', Rule::in([...SeguimientoDescargasEvento::CLASES, 'todas'])],
            'base' => ['nullable', 'integer', 'min:1'],
            'modalidad' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', Rule::in(SeguimientoDescargasEvento::ESTADOS)],
            'nombre' => ['nullable', 'string', 'max:120'],
            'documento' => ['nullable', 'string', 'max:40'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $superAdmin = $request->user()?->loadMissing('perfilOrganizador.rol')->rol === 'super-admin';
        // La búsqueda por documento EXACTO es solo del super-admin: el servidor la rechaza para cualquier otro rol (no basta con ocultarla en pantalla).
        abort_if(trim((string) ($f['documento'] ?? '')) !== '' && ! $superAdmin, 403, 'Solo un super-administrador puede buscar por documento.');

        $registros = $this->seguimiento->registros($evento);
        $filtros = array_filter($f, fn ($v, $k) => $k !== 'page' && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH) + ['clase' => 'oficial'];
        $listado = $this->seguimiento->listado($registros, $filtros, $superAdmin, (int) ($f['page'] ?? 1));
        $historico = $evento->origen !== EventoCertificacion::ORIGEN_CREDENTIAL_FLOW;

        return Inertia::render('CredentialFlow/Eventos/Descargas', [
            'evento' => ['id' => $evento->id, 'nombre' => $evento->nombre, 'anio' => $evento->anio, 'fecha_texto' => $evento->fecha_texto, 'historico' => $historico,
                'volver' => $historico ? route('credential-flow.historico.eventos.show', $evento) : route('credential-flow.eventos.show', $evento)],
            'resumen' => $this->seguimiento->resumen($registros),
            'listado' => $listado,
            'filtros' => $filtros,
            'opciones' => ['bases' => $this->seguimiento->bases($evento), 'modalidades' => $this->seguimiento->modalidades($registros)],
            'permisos' => ['documento_completo' => $superAdmin],
            'aviso' => self::AVISO,
            'reintento_segundos' => SeguimientoDescargasEvento::REINTENTO_SEGUNDOS,
        ]);
    }
}
