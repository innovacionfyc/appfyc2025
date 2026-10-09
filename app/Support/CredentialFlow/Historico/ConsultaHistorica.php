<?php

namespace App\Support\CredentialFlow\Historico;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\MigracionCorrida;
use App\Support\CredentialFlow\Legado\CodigoHistorico;
use App\Support\CredentialFlow\Legado\CodigoHistoricoException;
use App\Support\CredentialFlow\StagingEv\Normalizador;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de SOLO LECTURA del histórico (eventos, certificados, plantillas, descargas y correos legado) para el administrador.
 *
 * Nunca escribe: solo SELECT. Pagina siempre en el servidor y calcula los totales de la página con UNA consulta agrupada por tabla
 * (sin N+1 y sin cargar colecciones grandes). Devuelve arrays listos para la pantalla; la presentación (enmascarado, etiquetas,
 * mensajes) la decide PresentadorHistorico.
 */
final class ConsultaHistorica
{
    public const POR_PAGINA_EVENTOS = 20;

    public const POR_PAGINA_CERTIFICADOS = 25;

    public const POR_PAGINA_PLANTILLAS = 25;

    public const POR_PAGINA_BUSQUEDA = 25;

    public const LIMITE_DESCARGAS = 50;

    public const CONCILIACIONES = ['ok', 'duplicado_consolidado', 'pendiente_plantilla', 'pendiente_conciliacion', 'revision_documento'];

    public const FILTROS_PLANTILLA_EVENTO = ['ok', 'faltante', 'extension_invalida', 'sin_plantilla', 'con_candidata'];

    public const FILTROS_PLANTILLA = ['ok', 'faltante', 'extension_invalida', 'huerfana', 'candidata_revision', 'sin_evento'];

    // ── Resumen general ───────────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function resumen(): array
    {
        $porConciliacion = DB::table('cf_certificados_legado')->selectRaw('conciliacion_estado, COUNT(1) n')->groupBy('conciliacion_estado')->pluck('n', 'conciliacion_estado');

        return [
            'eventos' => DB::table('cf_eventos')->where('origen', 'legado')->whereNull('deleted_at')->count(),
            'certificados' => (int) $porConciliacion->sum(),
            'descargas' => DB::table('cf_descargas')->whereNotNull('certificado_legado_id')->count(),
            'plantillas' => DB::table('cf_plantillas_legado')->count(),
            'imagenes_sin_evento' => DB::table('cf_plantillas_legado')->whereIn('estado', ['huerfana', 'candidata_revision'])->count(),
            'por_conciliacion' => collect(self::CONCILIACIONES)->mapWithKeys(fn ($c) => [$c => (int) ($porConciliacion[$c] ?? 0)])->all(),
        ];
    }

    // ── Eventos ───────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{anios:list<int>} opciones de los filtros */
    public function opcionesEventos(): array
    {
        return ['anios' => DB::table('cf_eventos')->where('origen', 'legado')->whereNull('deleted_at')->whereNotNull('anio')->distinct()->orderByDesc('anio')->pluck('anio')->map(fn ($a) => (int) $a)->all()];
    }

    /** @param array<string,mixed> $f filtros ya validados */
    public function eventos(array $f): LengthAwarePaginator
    {
        $pag = $this->consultaEventos($f)->paginate(self::POR_PAGINA_EVENTOS);
        $ids = collect($pag->items())->pluck('id')->all();
        $certs = $this->agregadoCertificados($ids);
        $desc = $this->agregadoDescargas($ids);
        $oldIds = $this->oldIds('cf_eventos', $ids);

        return $pag->through(function ($e) use ($certs, $desc, $oldIds) {
            return $this->filaEvento($e, $certs[$e->id] ?? null, $desc[$e->id] ?? null, $oldIds[$e->id] ?? null);
        });
    }

    private function consultaEventos(array $f): Builder
    {
        $q = DB::table('cf_eventos as e')
            ->leftJoin('cf_plantillas_legado as p', 'p.id', '=', 'e.plantilla_legado_id')
            ->where('e.origen', 'legado')->whereNull('e.deleted_at')
            ->select('e.id', 'e.nombre', 'e.anio', 'e.estado', 'e.notas', 'e.plantilla_legado_id', 'p.estado as plantilla_estado', 'p.notas as notas_plantilla');

        if (($t = self::textoBusqueda($f['q'] ?? null)) !== null) {
            $q->where(fn ($w) => $w->where('e.nombre', 'like', "%{$t}%")->orWhere('e.nombre_normalizado', 'like', '%'.Normalizador::paraBuscar($t).'%'));
        }
        if (isset($f['anio']) && $f['anio'] !== '') {
            $f['anio'] === 'sin' ? $q->whereNull('e.anio') : $q->where('e.anio', (int) $f['anio']);
        }
        match ($f['plantilla'] ?? null) {
            'ok', 'faltante', 'extension_invalida' => $q->where('p.estado', $f['plantilla']),
            'sin_plantilla' => $q->whereNull('e.plantilla_legado_id'),
            'con_candidata' => $q->where('p.notas', 'like', '%evidencia_candidata%'),
            default => null,
        };
        if (in_array($f['conciliacion'] ?? null, self::CONCILIACIONES, true)) {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('cf_certificados_legado as c')->whereColumn('c.evento_id', 'e.id')->where('c.conciliacion_estado', $f['conciliacion']));
        }
        $conDescargas = fn ($s) => $s->selectRaw('1')->from('cf_certificados_legado as c')->join('cf_descargas as d', 'd.certificado_legado_id', '=', 'c.id')->whereColumn('c.evento_id', 'e.id');
        match ($f['descargas'] ?? null) {
            'con' => $q->whereExists($conDescargas),
            'sin' => $q->whereNotExists($conDescargas),
            default => null,
        };
        $conCodigo = fn ($s) => $s->selectRaw('1')->from('cf_certificados_legado as c')->whereColumn('c.evento_id', 'e.id')->whereNotNull('c.codigo_legado');
        match ($f['codigo'] ?? null) {
            'con' => $q->whereExists($conCodigo),
            'sin' => $q->whereNotExists($conCodigo),
            default => null,
        };

        // Orden inicial: año más reciente primero, luego nombre; los eventos sin año, al final.
        switch ($f['orden'] ?? 'anio') {
            case 'nombre':
                $q->orderBy('e.nombre')->orderBy('e.id');
                break;
            case 'certificados':
                $q->orderByDesc(DB::table('cf_certificados_legado as c')->selectRaw('COUNT(1)')->whereColumn('c.evento_id', 'e.id'))->orderBy('e.nombre')->orderBy('e.id');
                break;
            default:
                $q->orderByRaw('CASE WHEN e.anio IS NULL THEN 1 ELSE 0 END')->orderByDesc('e.anio')->orderBy('e.nombre')->orderBy('e.id');
        }

        return $q;
    }

    /** @return array<string,mixed> */
    private function filaEvento(object $e, ?object $c, ?object $d, ?string $oldId): array
    {
        $plantilla = PresentadorHistorico::plantilla($e->plantilla_estado);

        return [
            'id' => (int) $e->id,
            'old_id' => $oldId,
            'nombre' => $e->nombre,
            'anio' => $e->anio === null ? null : (int) $e->anio,
            'anio_etiqueta' => PresentadorHistorico::anio($e->anio === null ? null : (int) $e->anio),
            'estado' => PresentadorHistorico::estadoEvento($e->estado),
            'certificados' => (int) ($c->total ?? 0),
            'con_codigo' => (int) ($c->con_codigo ?? 0),
            'descargados' => (int) ($d->descargados ?? 0),
            'descargas' => (int) ($d->descargas ?? 0),
            'revision_documento' => (int) ($c->revision_documento ?? 0),
            'pendiente_conciliacion' => (int) ($c->pendiente_conciliacion ?? 0),
            'pendiente_plantilla' => (int) ($c->pendiente_plantilla ?? 0),
            'duplicados' => (int) ($c->duplicado_consolidado ?? 0),
            'plantilla_estado' => $e->plantilla_estado,
            'plantilla' => $plantilla,
            'con_candidata' => $e->plantilla_estado === 'faltante' && self::evidenciaDe($e->notas_plantilla ?? null) !== null,
        ];
    }

    /** @param list<int> $ids @return array<int,object> */
    private function agregadoCertificados(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('cf_certificados_legado')->whereIn('evento_id', $ids)->groupBy('evento_id')->selectRaw(
            "evento_id, COUNT(1) total, SUM(CASE WHEN codigo_legado IS NOT NULL THEN 1 ELSE 0 END) con_codigo,
             SUM(CASE WHEN conciliacion_estado = 'revision_documento' THEN 1 ELSE 0 END) revision_documento,
             SUM(CASE WHEN conciliacion_estado = 'pendiente_conciliacion' THEN 1 ELSE 0 END) pendiente_conciliacion,
             SUM(CASE WHEN conciliacion_estado = 'pendiente_plantilla' THEN 1 ELSE 0 END) pendiente_plantilla,
             SUM(CASE WHEN conciliacion_estado = 'duplicado_consolidado' THEN 1 ELSE 0 END) duplicado_consolidado"
        )->get()->keyBy('evento_id')->all();
    }

    /** @param list<int> $ids @return array<int,object> */
    private function agregadoDescargas(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('cf_descargas as d')->join('cf_certificados_legado as c', 'c.id', '=', 'd.certificado_legado_id')->whereIn('c.evento_id', $ids)
            ->groupBy('c.evento_id')->selectRaw('c.evento_id, COUNT(1) descargas, COUNT(DISTINCT d.certificado_legado_id) descargados')->get()->keyBy('evento_id')->all();
    }

    /** @param list<int> $ids @return array<int,string> destino_id => id del sistema viejo (según el mapa de migración) */
    private function oldIds(string $tabla, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('cf_migraciones_map')->where('destino_tabla', $tabla)->where('origen_tabla', '!=', 'conciliacion')->whereIn('destino_id', $ids)->whereIn('relacion', ['principal', 'canonico', 'duplicado_identico', 'variante_conflictiva'])
            ->pluck('origen_id', 'destino_id')->all();
    }

    // ── Detalle de evento ─────────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function evento(EventoCertificacion $evento): array
    {
        $fila = $this->consultaEventos([])->where('e.id', $evento->id)->first();
        $c = $this->agregadoCertificados([$evento->id])[$evento->id] ?? null;
        $d = $this->agregadoDescargas([$evento->id])[$evento->id] ?? null;
        $datos = $this->filaEvento($fila, $c, $d, $this->oldIds('cf_eventos', [$evento->id])[$evento->id] ?? null);

        $plantilla = $evento->plantilla_legado_id === null ? null : $this->plantillaDeEntrada($evento->plantilla_legado_id);
        $marcas = json_decode((string) $evento->notas, true)['marcas'] ?? [];

        $avisos = [];
        if ($plantilla === null || $plantilla['estado'] !== 'ok') {
            $tecnico = match ($plantilla['estado'] ?? null) {
                'faltante' => 'La imagen del evento no existe en el sistema histórico.',
                'extension_invalida' => 'La imagen existe, pero su nombre no tiene una extensión válida para generar el certificado.',
                default => 'El evento no tenía imagen de certificado.',
            };
            $avisos[] = ['tipo' => 'plantilla', 'tono' => 'amber', 'titulo' => 'Plantilla pendiente', 'texto' => 'No contamos todavía con una plantilla utilizable para generar los certificados de este evento.', 'tecnico' => $tecnico, 'evidencia' => $plantilla['evidencia_candidata'] ?? null];
        }
        if (($datos['revision_documento'] ?? 0) > 0) {
            $avisos[] = ['tipo' => 'documentos', 'tono' => 'rose', 'titulo' => 'Documentos en revisión', 'texto' => "{$datos['revision_documento']} certificados tienen un documento histórico que necesita revisión antes de habilitarse."];
        }
        if (($datos['pendiente_conciliacion'] ?? 0) > 0) {
            $avisos[] = ['tipo' => 'conflictos', 'tono' => 'rose', 'titulo' => 'Diferencias entre registros', 'texto' => "{$datos['pendiente_conciliacion']} certificados tienen diferencias con otro registro histórico de la misma persona y necesitan revisión."];
        }
        if (in_array('ANIO_NO_DEDUCIBLE', $marcas, true) || in_array('ANIO_AMBIGUO', $marcas, true)) {
            $avisos[] = ['tipo' => 'anio', 'tono' => 'slate', 'titulo' => 'Año sin identificar', 'texto' => 'No pudimos determinar con certeza el año de este evento; no se asigna uno.', 'tecnico' => in_array('ANIO_AMBIGUO', $marcas, true) ? 'El nombre menciona más de un año.' : 'El nombre y la imagen no indican un año.'];
        }

        return $datos + ['plantilla_detalle' => $plantilla, 'avisos' => $avisos, 'notas_marcas' => $marcas];
    }

    // ── Certificados ──────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $f */
    public function certificadosDeEvento(EventoCertificacion $evento, array $f): LengthAwarePaginator
    {
        $q = $this->baseCertificados()->where('c.evento_id', $evento->id);
        if (in_array($f['conciliacion'] ?? null, self::CONCILIACIONES, true)) {
            $q->where('c.conciliacion_estado', $f['conciliacion']);
        }
        if (($t = self::textoBusqueda($f['q'] ?? null)) !== null) {
            $q->where(fn ($w) => $w->where('c.nombre_completo', 'like', "%{$t}%")->orWhere('c.documento_clave', Normalizador::documentoClave($t))->orWhere('c.codigo_legado', preg_replace('/\D/', '', $t) ?: '-'));
        }

        return $this->paginarCertificados($q->orderBy('c.nombre_completo')->orderBy('c.id'), self::POR_PAGINA_CERTIFICADOS);
    }

    private function baseCertificados(): Builder
    {
        return DB::table('cf_certificados_legado as c')->join('cf_eventos as e', 'e.id', '=', 'c.evento_id')
            ->select('c.id', 'c.evento_id', 'c.nombre_completo', 'c.tipo_documento', 'c.documento', 'c.codigo_legado', 'c.conciliacion_estado', 'c.estado', 'c.grupo_duplicado', 'e.nombre as evento_nombre', 'e.anio as evento_anio');
    }

    private function paginarCertificados(Builder $q, int $porPagina): LengthAwarePaginator
    {
        $pag = $q->paginate($porPagina);
        $ids = collect($pag->items())->pluck('id')->all();
        $correos = $ids === [] ? collect() : DB::table('cf_correos')->whereIn('certificado_legado_id', $ids)->groupBy('certificado_legado_id')
            ->selectRaw("certificado_legado_id, SUM(CASE WHEN estado = 'valido' THEN 1 ELSE 0 END) validos, SUM(CASE WHEN estado = 'invalido' THEN 1 ELSE 0 END) invalidos")->get()->keyBy('certificado_legado_id');
        $descargas = $ids === [] ? collect() : DB::table('cf_descargas')->whereIn('certificado_legado_id', $ids)->groupBy('certificado_legado_id')->selectRaw('certificado_legado_id, COUNT(1) n')->pluck('n', 'certificado_legado_id');

        return $pag->through(fn ($c) => [
            'id' => (int) $c->id,
            'nombre' => $c->nombre_completo,
            'tipo_documento' => $c->tipo_documento,
            // Privacidad: en listados el documento va enmascarado y el correo es solo un indicador.
            'documento' => PresentadorHistorico::documentoEnmascarado($c->documento),
            'codigo_legado' => $c->codigo_legado,
            'conciliacion' => ['estado' => $c->conciliacion_estado] + PresentadorHistorico::conciliacion($c->conciliacion_estado),
            'correo' => PresentadorHistorico::indicadorCorreo((int) ($correos[$c->id]->validos ?? 0), (int) ($correos[$c->id]->invalidos ?? 0)),
            'descargas' => (int) ($descargas[$c->id] ?? 0),
            'estado' => PresentadorHistorico::estadoCertificado($c->estado),
            'duplicado' => $c->grupo_duplicado !== null,
            'evento' => ['id' => (int) $c->evento_id, 'nombre' => $c->evento_nombre, 'anio_etiqueta' => PresentadorHistorico::anio($c->evento_anio === null ? null : (int) $c->evento_anio)],
        ]);
    }

    /** @return array<string,mixed> */
    public function certificado(CertificadoLegado $c): array
    {
        $snap = json_decode((string) $c->getRawOriginal('snapshot_legado'), true) ?: [];
        $evento = EventoCertificacion::withTrashed()->find($c->evento_id);
        $oldId = $this->oldIds('cf_certificados_legado', [$c->id])[$c->id] ?? ($snap['migracion']['old_id'] ?? null);
        $corrida = $c->corrida_id === null ? null : MigracionCorrida::find($c->corrida_id);

        $plantillaPropia = $c->plantilla_legado_id === null ? null : $this->plantillaDeEntrada($c->plantilla_legado_id);
        $plantillaEvento = $evento?->plantilla_legado_id === null ? null : $this->plantillaDeEntrada($evento->plantilla_legado_id);
        $plantilla = $plantillaPropia ?? $plantillaEvento;

        $totalDescargas = DB::table('cf_descargas')->where('certificado_legado_id', $c->id)->count();
        $descargas = DB::table('cf_descargas')->where('certificado_legado_id', $c->id)->orderByDesc('descargado_at')->orderByDesc('id')->limit(self::LIMITE_DESCARGAS)->get(['via', 'descargado_at', 'emision_id'])
            ->map(fn ($d) => ['fecha' => $d->descargado_at, 'via' => ['portal' => 'Portal', 'correo' => 'Correo', 'admin' => 'Administración'][$d->via] ?? $d->via, 'origen' => $d->emision_id === null ? 'Histórico (sistema anterior)' : 'Moderno'])->all();
        $correos = DB::table('cf_correos')->where('certificado_legado_id', $c->id)->orderBy('orden')->orderBy('id')->get(['correo', 'correo_normalizado', 'estado', 'orden', 'es_principal', 'origen'])
            ->map(fn ($x) => ['correo' => $x->correo, 'normalizado' => $x->correo_normalizado, 'estado' => $x->estado === 'valido' ? 'Válido' : 'Inválido', 'valido' => $x->estado === 'valido', 'orden' => (int) $x->orden, 'principal' => (bool) $x->es_principal, 'origen' => $x->origen === 'legado' ? 'Histórico' : 'Credential Flow'])->all();

        $duplicado = $this->bloqueDuplicados($c, $snap);

        return [
            'id' => (int) $c->id,
            'old_id' => $oldId,
            'nombre' => $c->nombre_completo,
            'tipo_documento' => $c->tipo_documento,
            'documento' => $c->documento,
            'documento_enmascarado' => PresentadorHistorico::documentoEnmascarado($c->documento),
            'correo_original' => $c->correo,
            'correo_estado' => ['valido' => 'Válido', 'multiple' => 'Múltiples correos históricos', 'invalido' => 'Inválido', 'sin_correo' => 'Sin correo'][$c->correo_estado] ?? $c->correo_estado,
            'correos' => $correos,
            'correos_multiples' => count(array_filter($correos, fn ($x) => $x['valido'])) > 1,
            'codigo_legado' => $c->codigo_legado,
            'codigo' => $this->codigoEfectivo($c),
            'estado' => PresentadorHistorico::estadoCertificado($c->estado),
            'conciliacion' => ['estado' => $c->conciliacion_estado] + PresentadorHistorico::conciliacion($c->conciliacion_estado),
            'visible_portal' => (bool) $c->visible_portal,
            'evento' => $evento === null ? null : ['id' => (int) $evento->id, 'nombre' => $evento->nombre, 'anio_etiqueta' => PresentadorHistorico::anio($evento->anio)],
            'plantilla' => $plantilla,
            'plantilla_usable' => $plantillaPropia !== null,
            'pdf' => ['congelado' => $c->pdf_archivo !== null, 'estado' => $c->pdf_archivo !== null ? 'PDF congelado' : 'Aún no generado', 'materializado_at' => $c->materializado_at?->toDateTimeString(), 'intentos' => (int) $c->intentos_generacion],
            'reemplazado_por' => $c->reemplazado_por_emision_id === null ? null : ['emision_id' => (int) $c->reemplazado_por_emision_id],
            'corrida' => $corrida === null ? null : ['id' => (int) $corrida->id, 'estado' => $corrida->estado, 'fecha' => $corrida->iniciado_at?->toDateTimeString()],
            'creado_at' => $c->created_at?->toDateTimeString(),
            'descargas' => ['total' => $totalDescargas, 'mostradas' => count($descargas), 'limite' => self::LIMITE_DESCARGAS, 'items' => $descargas],
            'duplicado' => $duplicado,
            'avisos' => $this->avisosCertificado($c, $snap, $plantilla, $duplicado),
        ];
    }

    /**
     * Código del par: «legado» (existía en el sistema viejo), «credential_flow» (asignado al generar el PDF) o «sin_asignar». Solo para el
     * administrador: la vista pública no revela el origen.
     *
     * @return array{origen:string,codigo:?string,texto:string}
     */
    private function codigoEfectivo(CertificadoLegado $c): array
    {
        try {
            $r = (new CodigoHistorico)->resolver($c);
        } catch (CodigoHistoricoException) {
            return ['origen' => 'conflicto', 'codigo' => null, 'texto' => 'Códigos en conflicto: revisar'];
        }

        return match ($r['origen'] ?? null) {
            CodigoHistorico::ORIGEN_LEGADO => ['origen' => 'legado', 'codigo' => $r['codigo'], 'texto' => 'Legado: '.$r['codigo']],
            CodigoHistorico::ORIGEN_CREDENTIAL_FLOW => ['origen' => 'credential_flow', 'codigo' => $r['codigo'], 'texto' => 'Asignado por Credential Flow: '.$r['codigo']],
            default => ['origen' => 'sin_asignar', 'codigo' => null, 'texto' => 'Aún no asignado (se asigna al generar el certificado)'],
        };
    }

    /** @return array<string,mixed>|null */
    private function bloqueDuplicados(CertificadoLegado $c, array $snap): ?array
    {
        if ($c->grupo_duplicado === null) {
            return null;
        }
        $hermanos = DB::table('cf_certificados_legado')->where('grupo_duplicado', $c->grupo_duplicado)->orderBy('id')->get(['id', 'conciliacion_estado']);
        $oldIds = $this->oldIds('cf_certificados_legado', $hermanos->pluck('id')->all());
        $d = $snap['duplicado'] ?? [];
        $canonicoOld = $d['canonico_old_id'] ?? null;
        $clasificacion = $d['clasificacion'] ?? null;

        return [
            'clasificacion' => $clasificacion,
            'canonico' => $clasificacion === 'identico' ? ((string) ($snap['migracion']['old_id'] ?? '') === (string) $canonicoOld) : null,
            'total' => $hermanos->count(),
            'diferencias' => PresentadorHistorico::diferencias($d['etiquetas'] ?? null),
            'subtipo' => $d['subtipo'] ?? null,
            'variantes' => $hermanos->map(fn ($h) => [
                'id' => (int) $h->id, 'old_id' => $oldIds[$h->id] ?? null, 'actual' => (int) $h->id === (int) $c->id,
                'canonico' => $clasificacion === 'identico' && (string) ($oldIds[$h->id] ?? '') === (string) $canonicoOld,
                'conciliacion' => ['estado' => $h->conciliacion_estado] + PresentadorHistorico::conciliacion($h->conciliacion_estado),
            ])->all(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function avisosCertificado(CertificadoLegado $c, array $snap, ?array $plantilla, ?array $duplicado): array
    {
        $avisos = [];
        if (($snap['documento_estado'] ?? 'valido') !== 'valido') {
            $avisos[] = ['tipo' => 'documento', 'tono' => 'rose', 'titulo' => 'Documento en revisión', 'texto' => 'El documento histórico necesita revisión antes de habilitar este certificado.',
                'tecnico' => 'Motivo: '.PresentadorHistorico::motivoDocumento($snap['documento_estado'] ?? null, $snap['documento_detalle'] ?? null).'.'];
        }
        // Un conflicto de solo código ya consolidado (10B-2A) deja de avisar de «diferencias»: el certificado quedó conciliado.
        if ($duplicado !== null && $duplicado['clasificacion'] === 'conflictivo' && $c->conciliacion_estado !== CertificadoLegado::CONCILIACION_PENDIENTE && DB::table('cf_migraciones_map')->where('origen_tabla', 'conciliacion')->where('relacion', 'canonico')->exists()) {
            $avisos[] = ['tipo' => 'duplicado', 'tono' => 'sky', 'titulo' => 'Diferencia de código consolidada', 'texto' => 'Las variantes de este registro diferían solo en la asignación tardía del código de verificación y fueron consolidadas.'];
        } elseif ($duplicado !== null && $duplicado['clasificacion'] === 'conflictivo') {
            $avisos[] = ['tipo' => 'conflicto', 'tono' => 'rose', 'titulo' => 'Diferencias entre registros históricos', 'texto' => 'Encontramos diferencias entre registros históricos de esta persona. Este certificado necesita revisión antes de habilitarse.',
                'tecnico' => $duplicado['diferencias'] === [] ? null : 'Difiere: '.implode(', ', $duplicado['diferencias']).'.'];
        }
        if ($duplicado !== null && $duplicado['clasificacion'] === 'identico') {
            $avisos[] = ['tipo' => 'duplicado', 'tono' => 'sky', 'titulo' => 'Registro duplicado', 'texto' => 'Este registro pertenece a un grupo duplicado histórico con datos idénticos.'];
        }
        if ($c->plantilla_legado_id === null) {
            $estado = $plantilla['estado'] ?? null;
            $tecnico = match ($estado) {
                'faltante' => 'La imagen del evento no existe en el sistema histórico.',
                'extension_invalida' => 'La imagen del evento no tiene una extensión válida para generar el certificado.',
                default => 'El evento no tenía imagen de certificado.',
            };
            $avisos[] = ['tipo' => 'plantilla', 'tono' => 'amber', 'titulo' => 'Plantilla pendiente', 'texto' => 'No contamos todavía con una plantilla utilizable para generar este certificado.', 'tecnico' => $tecnico, 'evidencia' => $plantilla['evidencia_candidata'] ?? null];
        }
        foreach ($snap['advertencias'] ?? [] as $codigo) {
            if (($texto = PresentadorHistorico::advertencia($codigo)) !== null) {
                $avisos[] = ['tipo' => 'advertencia', 'tono' => 'slate', 'titulo' => 'Dato a tener en cuenta', 'texto' => $texto];
            }
        }
        if (count(array_filter($snap['motivos'] ?? [], fn ($m) => $m === 'CORREO_MULTIPLE')) > 0 || $c->correo_estado === 'multiple') {
            $avisos[] = ['tipo' => 'correos', 'tono' => 'slate', 'titulo' => 'Múltiples correos históricos', 'texto' => 'Este registro tenía más de un correo; se conservan todos y ninguno se eligió como principal.'];
        }

        return $avisos;
    }

    // ── Plantillas ────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function plantillaDeEntrada(int $id): ?array
    {
        $p = DB::table('cf_plantillas_legado as p')->leftJoin('cf_plantillas_legado_contenidos as k', 'k.id', '=', 'p.contenido_id')->where('p.id', $id)
            ->first(['p.id', 'p.estado', 'p.nombre_original', 'p.renderizable', 'p.motivo_no_renderizable', 'p.notas', 'k.sha256', 'k.mime_real', 'k.ancho_px', 'k.alto_px', 'k.bytes']);

        return $p === null ? null : $this->filaPlantilla($p) + ['evidencia_candidata' => self::evidenciaDe($p->notas)];
    }

    /** @return array<string,mixed> */
    private function filaPlantilla(object $p): array
    {
        return [
            'id' => (int) $p->id,
            'estado' => $p->estado,
            'estado_info' => PresentadorHistorico::plantilla($p->estado),
            'nombre' => PresentadorHistorico::nombreArchivo($p->nombre_original),
            'renderizable' => (bool) $p->renderizable,
            'contenido' => $p->sha256 !== null,
            'sha' => PresentadorHistorico::shaAbreviado($p->sha256),
            'mime' => $p->mime_real,
            'dimensiones' => $p->ancho_px === null ? null : "{$p->ancho_px} × {$p->alto_px} px",
            'bytes' => $p->bytes === null ? null : PresentadorHistorico::bytes((int) $p->bytes),
        ];
    }

    /** @return array<string,mixed>|null evidencia técnica de una candidata (la guardó la migración en las notas de la entrada faltante) */
    public static function evidenciaDe(?string $notas): ?array
    {
        if ($notas === null || ! str_contains($notas, 'evidencia_candidata')) {
            return null;
        }
        $e = json_decode($notas, true)['evidencia_candidata'] ?? null;

        return $e === null ? null : [
            'sha' => PresentadorHistorico::shaAbreviado($e['candidata_sha256'] ?? null), 'mime' => $e['mime_real'] ?? null,
            'dimensiones' => isset($e['ancho_px']) ? "{$e['ancho_px']} × {$e['alto_px']} px" : null, 'bytes' => isset($e['bytes']) ? PresentadorHistorico::bytes((int) $e['bytes']) : null,
            'similitud' => isset($e['similitud_nombre']['similar_text_pct']) ? $e['similitud_nombre']['similar_text_pct'].' %' : null,
            'nota' => 'Solo evidencia técnica: no se enlaza automáticamente.',
        ];
    }

    /** @param array<string,mixed> $f */
    public function plantillas(array $f): LengthAwarePaginator
    {
        $q = DB::table('cf_plantillas_legado as p')->leftJoin('cf_plantillas_legado_contenidos as k', 'k.id', '=', 'p.contenido_id')
            ->select('p.id', 'p.estado', 'p.nombre_original', 'p.renderizable', 'p.motivo_no_renderizable', 'p.notas', 'p.contenido_id', 'k.sha256', 'k.mime_real', 'k.ancho_px', 'k.alto_px', 'k.bytes');

        match ($f['estado'] ?? null) {
            'ok', 'faltante', 'extension_invalida', 'huerfana', 'candidata_revision' => $q->where('p.estado', $f['estado']),
            'sin_evento' => $q->whereIn('p.estado', ['huerfana', 'candidata_revision']),
            default => null,
        };
        match ($f['renderizable'] ?? null) {
            'si' => $q->where('p.renderizable', true),
            'no' => $q->where('p.renderizable', false),
            default => null,
        };
        if (($t = self::textoBusqueda($f['q'] ?? null)) !== null) {
            $q->where(fn ($w) => $w->where('p.nombre_original', 'like', "%{$t}%")->orWhere('p.nombre_normalizado', 'like', '%'.Normalizador::claveArchivo($t).'%')->orWhere('k.sha256', 'like', strtolower(preg_replace('/[^0-9a-fA-F]/', '', $t) ?: '-').'%'));
        }
        $q->orderBy('p.id');

        $pag = $q->paginate(self::POR_PAGINA_PLANTILLAS);
        $ids = collect($pag->items())->pluck('id')->all();
        $eventos = $ids === [] ? collect() : DB::table('cf_eventos')->whereIn('plantilla_legado_id', $ids)->whereNull('deleted_at')->orderBy('id')->get(['id', 'nombre', 'anio', 'plantilla_legado_id'])->groupBy('plantilla_legado_id');
        $candidatas = $this->candidatasPorSha();

        return $pag->through(function ($p) use ($eventos, $candidatas) {
            $ev = $eventos[$p->id] ?? collect();

            return $this->filaPlantilla($p) + [
                'eventos' => $ev->map(fn ($e) => ['id' => (int) $e->id, 'nombre' => $e->nombre, 'anio_etiqueta' => PresentadorHistorico::anio($e->anio === null ? null : (int) $e->anio)])->values()->all(),
                'sin_evento' => $ev->isEmpty() && in_array($p->estado, ['huerfana', 'candidata_revision'], true),
                // Para una candidata: a qué evento (con imagen faltante) se parece y con qué similitud. SIN enlazar.
                'candidata_para' => $p->estado === 'candidata_revision' ? ($candidatas[$p->sha256] ?? null) : null,
            ];
        });
    }

    /** @return array<string,array<string,mixed>> sha de la candidata => evidencia + evento al que se parece (por las notas de la migración) */
    private function candidatasPorSha(): array
    {
        $r = [];
        foreach (DB::table('cf_plantillas_legado')->where('estado', 'faltante')->where('notas', 'like', '%evidencia_candidata%')->get(['id', 'notas']) as $f) {
            $e = json_decode((string) $f->notas, true)['evidencia_candidata'] ?? null;
            if ($e === null) {
                continue;
            }
            $ev = DB::table('cf_eventos')->where('plantilla_legado_id', $f->id)->whereNull('deleted_at')->orderBy('id')->first(['id', 'nombre', 'anio']);
            $r[$e['candidata_sha256']] = (self::evidenciaDe($f->notas) ?? []) + ['evento' => $ev === null ? null : ['id' => (int) $ev->id, 'nombre' => $ev->nombre, 'anio_etiqueta' => PresentadorHistorico::anio($ev->anio === null ? null : (int) $ev->anio)]];
        }

        return $r;
    }

    /** @return array<string,int> conteos por estado para los filtros de plantillas */
    public function conteosPlantillas(): array
    {
        $c = DB::table('cf_plantillas_legado')->selectRaw('estado, COUNT(1) n')->groupBy('estado')->pluck('n', 'estado');

        return [
            'total' => (int) $c->sum(), 'ok' => (int) ($c['ok'] ?? 0), 'faltante' => (int) ($c['faltante'] ?? 0), 'extension_invalida' => (int) ($c['extension_invalida'] ?? 0),
            'huerfana' => (int) ($c['huerfana'] ?? 0), 'candidata_revision' => (int) ($c['candidata_revision'] ?? 0), 'sin_evento' => (int) (($c['huerfana'] ?? 0) + ($c['candidata_revision'] ?? 0)),
        ];
    }

    // ── Búsqueda administrativa ───────────────────────────────────────────────────────────────────────────

    /**
     * Busca certificados por nombre (parcial, mínimo 3 letras), documento (exacto, normalizado) o código legado (exacto). Siempre en el
     * servidor y paginado. El código NO es único: devuelve todas las filas que lo tengan (incluidas las variantes de un duplicado).
     */
    public function buscarCertificados(string $campo, string $q): ?LengthAwarePaginator
    {
        $q = trim($q);
        if ($q === '') {
            return null;
        }
        $consulta = $this->baseCertificados();
        switch ($campo) {
            case 'documento':
                $clave = Normalizador::documentoClave($q);
                if ($clave === '') {
                    return $this->vacio();
                }
                $consulta->where('c.documento_clave', $clave);
                break;
            case 'codigo':
                $codigo = preg_replace('/\D/', '', $q) ?? '';
                if ($codigo === '') {
                    return $this->vacio();
                }
                // Código del sistema viejo O asignado por Credential Flow (a todas las filas del par evento + documento).
                $consulta->where(fn ($w) => $w->where('c.codigo_legado', $codigo)->orWhereExists(fn ($s) => $s->selectRaw('1')->from('cf_codigos_historicos as h')
                    ->join('cf_certificados_legado as k', 'k.id', '=', 'h.certificado_canonico_id')->where('h.codigo', $codigo)
                    ->whereColumn('k.evento_id', 'c.evento_id')->whereColumn('k.documento_clave', 'c.documento_clave')));
                break;
            default:
                $t = self::textoBusqueda($q);
                if ($t === null || mb_strlen($t) < 3) {
                    return $this->vacio();
                }
                $consulta->where('c.nombre_completo', 'like', "%{$t}%");
        }

        return $this->paginarCertificados($consulta->orderBy('e.anio', 'desc')->orderBy('c.nombre_completo')->orderBy('c.id'), self::POR_PAGINA_BUSQUEDA);
    }

    private function vacio(): LengthAwarePaginator
    {
        return new \Illuminate\Pagination\LengthAwarePaginator(new Collection, 0, self::POR_PAGINA_BUSQUEDA, 1, ['path' => request()->url()]);
    }

    /** Texto de búsqueda seguro para LIKE (sin comodines del usuario) o null si está vacío. */
    private static function textoBusqueda(mixed $valor): ?string
    {
        $t = trim(str_replace(['%', '_', '\\'], ' ', (string) $valor));
        $t = preg_replace('/\s+/u', ' ', $t) ?? '';

        return $t === '' ? null : mb_substr($t, 0, 80);
    }
}
