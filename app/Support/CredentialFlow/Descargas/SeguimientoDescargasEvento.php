<?php

namespace App\Support\CredentialFlow\Descargas;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Lote;
use App\Support\CredentialFlow\Historico\PresentadorHistorico;
use App\Support\CredentialFlow\Participantes\DobleModalidad;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\FamiliaDocumento;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Verificacion\ClasificacionEmision;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seguimiento de descargas de UN evento (SOLO LECTURA: nunca escribe en cf_descargas ni en ninguna otra tabla). Lee lo que el portal ya registra y lo
 * presenta por CERTIFICADO, nunca solo por documento:
 *
 *  - MODERNOS: un registro por PARTICIPACIÓN (la doble modalidad son dos participaciones, dos certificados). Cada participación conserva TODAS sus versiones
 *    (reemisiones: mismo participante, `reemplaza_id`). Las descargas de la versión VIGENTE se cuentan aparte de las de versiones anteriores: una descarga
 *    antigua NUNCA hace «descargada» a la versión nueva. Oficial / prueba interna (QA) se decide por el DOCUMENTO EMITIDO (ClasificacionEmision, igual que la
 *    verificación pública y el buscador); la marca viva del participante solo se usa para avisar si no coincide.
 *  - HISTÓRICOS: un registro por certificado LÓGICO (`grupo_duplicado` o la propia fila, la regla del portal); las descargas de sus filas se suman una sola
 *    vez. Se separan las IMPORTADAS del sistema anterior (`legado_importado`) de las registradas por Credential Flow. Un histórico REEMPLAZADO conserva sus
 *    descargas anteriores al reemplazo; las posteriores están en su emisión moderna (base «Reemplazos históricos» del mismo evento) y se cuentan allí.
 *
 * Cada fila de cf_descargas pertenece a exactamente una emisión o un histórico, así que nada se cuenta dos veces. Una descarga registrada significa que el
 * portal ENTREGÓ el archivo; no prueba que el titular lo recibiera, lo abriera ni lo leyera, ni que quien lo descargó fuera el titular.
 *
 * Indicador secundario «posibles reintentos»: descargas del mismo certificado desde la misma IP (HMAC) a menos de REINTENTO_SEGUNDOS de la anterior. Solo
 * para las registradas por Credential Flow (las importadas no tienen IP). No altera ningún conteo principal.
 */
final class SeguimientoDescargasEvento
{
    public const REINTENTO_SEGUNDOS = 120;

    public const POR_PAGINA = 25;

    public const TIPOS = ['moderno', 'historico'];

    public const CLASES = ['oficial', 'prueba_interna', 'indeterminada'];

    public const ESTADOS = ['descargado', 'pendiente', 'revocado', 'reemplazado'];

    private const BLOQUE = 500;

    /**
     * Todos los registros del evento (modernos e históricos) con sus métricas. Datos personales: `nombre` y `documento` (completo; quien presenta decide si
     * enmascarar). Los campos que empiezan por `_` son internos (filtros) y no deben salir hacia la pantalla.
     *
     * @return list<array<string,mixed>>
     */
    public function registros(EventoCertificacion $evento): array
    {
        return [...$this->modernos($evento), ...$this->historicos($evento)];
    }

    /**
     * Indicadores del evento a partir de los MISMOS registros del listado.
     *
     * @param  list<array<string,mixed>>  $registros
     * @return array<string,mixed>
     */
    public function resumen(array $registros): array
    {
        $mod = collect($registros)->where('tipo', 'moderno');
        $porClase = [];
        foreach (self::CLASES as $clase) {
            $porClase[$clase] = $this->resumenModerno($mod->where('clase', $clase));
        }
        $oficiales = $mod->where('clase', ClasificacionEmision::OFICIAL)->where('estado', 'vigente');
        $agrupar = function (Collection $filas, string $campo, callable $etiqueta): array {
            return $filas->groupBy($campo)->map(fn (Collection $g, $k) => [
                'clave' => (string) $k, 'etiqueta' => $etiqueta($g->first()),
                'vigentes' => $g->count(), 'con_descarga' => $g->where('descargado', true)->count(),
                'pendientes' => $g->where('descargado', false)->count(), 'solicitudes' => $g->sum(fn ($r) => $r['descargas']['vigente']['solicitudes']),
            ])->sortBy('etiqueta')->values()->all();
        };

        return [
            'modernos' => $porClase,
            'por_modalidad' => $agrupar($oficiales, '_modalidad', fn ($r) => $r['modalidad'] ?? 'Sin modalidad'),
            'por_base' => $agrupar($oficiales, '_base', fn ($r) => $r['base']['nombre'] ?? '—'),
            'historicos' => $this->resumenHistorico(collect($registros)->where('tipo', 'historico')),
            'clasificacion_distinta_a_marca' => $mod->sum('_clasificacion_distinta'),
        ];
    }

    /**
     * Filtra, ordena y pagina. `$documentoCompleto`: el documento sale completo (super-admin) o enmascarado. El filtro de documento EXACTO solo debe llegar
     * aquí si quien llama ya comprobó el permiso.
     *
     * @param  list<array<string,mixed>>  $registros
     * @param  array<string,mixed>  $f
     * @return array{items:list<array<string,mixed>>, total:int, pagina:int, por_pagina:int, ultima:int}
     */
    public function listado(array $registros, array $f, bool $documentoCompleto, int $pagina = 1, int $porPagina = self::POR_PAGINA): array
    {
        $nombre = trim((string) ($f['nombre'] ?? '')) === '' ? null : NombreConservador::normalizar((string) $f['nombre']);
        // Documento EXACTO por su familia de claves (con o sin tipo delante, la misma regla del portal y del buscador): nunca por parecido ni por prefijo.
        $claves = trim((string) ($f['documento'] ?? '')) === '' ? null : FamiliaDocumento::claves(Texto::claveDocumento((string) $f['documento']));
        $desde = $f['desde'] ?? null;
        $hasta = $f['hasta'] ?? null;

        $filas = collect($registros)->filter(function (array $r) use ($f, $nombre, $claves, $desde, $hasta): bool {
            if (in_array($f['tipo'] ?? null, self::TIPOS, true) && $r['tipo'] !== $f['tipo']) {
                return false;
            }
            // Clase: los históricos no tienen pruebas internas (cuentan como oficiales).
            $clase = $f['clase'] ?? ClasificacionEmision::OFICIAL;
            if ($clase !== 'todas' && ($r['tipo'] === 'moderno' ? $r['clase'] !== $clase : $clase !== ClasificacionEmision::OFICIAL)) {
                return false;
            }
            if (! empty($f['base']) && ($r['base']['id'] ?? null) !== (int) $f['base']) {
                return false;
            }
            if (! empty($f['modalidad']) && ($r['_modalidad'] ?? null) !== DobleModalidad::efectiva($f['modalidad'], null)) {
                return false;
            }
            if (in_array($f['estado'] ?? null, self::ESTADOS, true)) {
                $ok = match ($f['estado']) {
                    'descargado' => in_array($r['estado'], ['vigente'], true) && $r['descargado'],
                    'pendiente' => $r['estado'] === 'vigente' && ! $r['descargado'],
                    'revocado' => $r['estado'] === 'revocado',
                    'reemplazado' => $r['estado'] === 'reemplazado',
                };
                if (! $ok) {
                    return false;
                }
            }
            if ($nombre !== null && ! str_contains($r['_nombre'], $nombre)) {
                return false;
            }
            if ($claves !== null && ! in_array($r['_documento_clave'], $claves, true)) {
                return false;
            }
            if ($desde !== null || $hasta !== null) {
                $en = collect($r['_fechas'])->contains(fn (string $t) => ($desde === null || substr($t, 0, 10) >= $desde) && ($hasta === null || substr($t, 0, 10) <= $hasta));
                if (! $en) {
                    return false;
                }
            }

            return true;
        })->sortBy([fn ($a, $b) => strcmp($a['tipo'], $b['tipo']) * -1, fn ($a, $b) => strcmp($a['base']['nombre'] ?? '', $b['base']['nombre'] ?? ''), fn ($a, $b) => strcmp($a['_nombre'], $b['_nombre']), fn ($a, $b) => $a['clave'] <=> $b['clave']])->values();

        $total = $filas->count();
        $ultima = max(1, (int) ceil($total / $porPagina));
        $pagina = min(max(1, $pagina), $ultima);
        $items = $filas->slice(($pagina - 1) * $porPagina, $porPagina)->map(function (array $r) use ($documentoCompleto) {
            $r['documento'] = $documentoCompleto ? $r['documento'] : PresentadorHistorico::documentoEnmascarado($r['documento']);

            return array_filter($r, fn ($k) => ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY);
        })->values()->all();

        return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'ultima' => $ultima];
    }

    /** @return list<array{clave:string,etiqueta:string}> modalidades efectivas presentes (para el filtro) */
    public function modalidades(array $registros): array
    {
        return collect($registros)->where('tipo', 'moderno')->filter(fn ($r) => $r['_modalidad'] !== '')
            ->unique('_modalidad')->map(fn ($r) => ['clave' => $r['_modalidad'], 'etiqueta' => $r['modalidad']])->sortBy('etiqueta')->values()->all();
    }

    /** @return list<array{id:int,nombre:string}> bases del evento (para el filtro) */
    public function bases(EventoCertificacion $evento): array
    {
        return Lote::withTrashed()->where('evento_id', $evento->id)->orderBy('id')->get(['id', 'nombre'])->map(fn (Lote $l) => ['id' => (int) $l->id, 'nombre' => (string) $l->nombre])->all();
    }

    /**
     * Indicadores del LISTADO de eventos con el MISMO criterio que la pestaña (`resumen()['modernos']['oficial']`): certificados vigentes cuyo documento
     * emitido es OFICIAL (ClasificacionEmision, igual que la verificación pública y el buscador) y cuántos de ellos tienen al menos una descarga en su
     * versión vigente. `distintas`: vigentes cuya marca viva de participante de control no coincide con el documento emitido (por eso el denominador puede
     * diferir de la columna «Certificados vigentes», que cuenta por la marca). Consultas fijas para todo el listado: una + una por bloque de diseños.
     *
     * @param  list<int>  $eventoIds
     * @return array<int, array{vigentes:int, con_descarga:int, distintas:int}>
     */
    public function indicadoresPorEvento(array $eventoIds): array
    {
        if ($eventoIds === []) {
            return [];
        }
        $vigentes = DB::table('cf_emisiones as e')->join('cf_lotes as l', 'l.id', '=', 'e.lote_id')->leftJoin('cf_participantes as p', 'p.id', '=', 'e.participante_id')
            ->whereIn('l.evento_id', $eventoIds)->where('e.estado', Emision::EMITIDA)
            ->selectRaw('e.id, l.evento_id, p.es_control_qa, EXISTS (SELECT 1 FROM cf_descargas d WHERE d.emision_id = e.id) AS descargada')->get();
        $clases = $this->clasificar($vigentes->pluck('id')->all());

        $salida = [];
        foreach ($vigentes as $v) {
            $r = &$salida[(int) $v->evento_id];
            $r ??= ['vigentes' => 0, 'con_descarga' => 0, 'distintas' => 0];
            $clase = $clases[(int) $v->id] ?? ClasificacionEmision::INDETERMINADA;
            if ($clase === ClasificacionEmision::OFICIAL) {
                $r['vigentes']++;
                $r['con_descarga'] += (bool) $v->descargada ? 1 : 0;
            }
            if ($v->es_control_qa !== null && $clase !== ClasificacionEmision::INDETERMINADA && ($clase === ClasificacionEmision::PRUEBA_INTERNA) !== (bool) $v->es_control_qa) {
                $r['distintas']++;
            }
            unset($r);
        }

        return $salida;
    }

    // ── Modernos ──────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function modernos(EventoCertificacion $evento): array
    {
        $lotes = Lote::withTrashed()->where('evento_id', $evento->id)->get(['id', 'nombre', 'datos_comunes'])->keyBy('id');
        if ($lotes->isEmpty()) {
            return [];
        }
        $emisiones = DB::table('cf_emisiones')->whereIn('lote_id', $lotes->keys())->orderBy('participante_id')->orderBy('version')->orderBy('id')
            ->get(['id', 'participante_id', 'lote_id', 'codigo', 'version', 'estado', 'emitido_at', 'revocado_at', 'reemplaza_id']);
        if ($emisiones->isEmpty()) {
            return [];
        }
        $ids = $emisiones->pluck('id')->all();
        $clases = $this->clasificar($ids);
        $descargas = $this->descargasPor('emision_id', $ids);
        $participantes = collect();
        foreach (array_chunk($emisiones->pluck('participante_id')->unique()->values()->all(), self::BLOQUE) as $bloque) {
            // union (no merge): conserva las claves = id del participante.
            $participantes = $participantes->union(DB::table('cf_participantes')->whereIn('id', $bloque)
                ->get(['id', 'lote_id', 'nombre_completo', 'documento', 'documento_clave', 'modalidad', 'es_control_qa', 'deleted_at'])->keyBy('id'));
        }
        // Emisión moderna que REEMPLAZÓ a un histórico (primera versión de la cadena) → id del histórico.
        $reemplazos = collect();
        foreach (array_chunk($ids, self::BLOQUE) as $bloque) {
            $reemplazos = $reemplazos->union(DB::table('cf_certificados_legado')->whereIn('reemplazado_por_emision_id', $bloque)->pluck('id', 'reemplazado_por_emision_id'));
        }

        $filas = [];
        foreach ($emisiones->groupBy('participante_id') as $pid => $versiones) {
            $p = $participantes->get($pid);
            $vigente = $versiones->firstWhere('estado', Emision::EMITIDA);
            $ultima = $versiones->last();
            $referencia = $vigente ?? $ultima;
            $lote = $lotes->get($referencia->lote_id);
            $general = $lote?->datos_comunes['modalidad'] ?? null;
            $modalidadTexto = trim((string) ($p?->modalidad ?? '')) !== '' ? trim((string) $p->modalidad) : (trim((string) $general) !== '' ? trim((string) $general) : null);

            $dVigente = $vigente === null ? $this->vacio() : $this->metricas($descargas[$vigente->id] ?? []);
            $anteriores = $versiones->filter(fn ($e) => $vigente === null || $e->id !== $vigente->id);
            $dAnteriores = $this->metricas($anteriores->flatMap(fn ($e) => $descargas[$e->id] ?? [])->sortBy('t')->values()->all());
            $todas = $versiones->flatMap(fn ($e) => $descargas[$e->id] ?? []);
            $clase = $clases[$referencia->id] ?? ClasificacionEmision::INDETERMINADA;
            $origen = $versiones->first(fn ($e) => $reemplazos->has($e->id));

            $filas[] = [
                'tipo' => 'moderno',
                'clave' => 'm:'.$pid,
                'participacion_id' => (int) $pid,
                'nombre' => (string) ($p?->nombre_completo ?? ''),
                'documento' => (string) ($p?->documento ?? ''),
                'modalidad' => $modalidadTexto,
                'base' => ['id' => (int) $referencia->lote_id, 'nombre' => (string) ($lote?->nombre ?? '')],
                'clase' => $clase,
                'estado' => $vigente !== null ? 'vigente' : 'revocado',
                'emision_id' => (int) $referencia->id,
                'codigo' => (string) $referencia->codigo,
                'version' => (int) $referencia->version,
                'versiones' => $versiones->count(),
                'emitido_at' => $referencia->emitido_at,
                'descargado' => $dVigente['solicitudes'] > 0,
                'descargas' => ['vigente' => $dVigente, 'anteriores' => $dAnteriores],
                'reintentos' => $this->reintentos($versiones->map(fn ($e) => $descargas[$e->id] ?? [])->all()),
                'reemplaza_historico' => $origen === null ? null : (int) $reemplazos[$origen->id],
                'participante_eliminado' => $p === null || $p->deleted_at !== null,
                '_modalidad' => DobleModalidad::efectiva($p?->modalidad, $general),
                '_base' => (int) $referencia->lote_id,
                '_nombre' => NombreConservador::normalizar((string) ($p?->nombre_completo ?? '')),
                '_documento_clave' => (string) ($p?->documento_clave ?? ''),
                '_fechas' => $todas->pluck('t')->all(),
                '_clasificacion_distinta' => $p !== null && $clase !== ClasificacionEmision::INDETERMINADA
                    && (($clase === ClasificacionEmision::PRUEBA_INTERNA) !== (bool) $p->es_control_qa) ? 1 : 0,
            ];
        }

        return $filas;
    }

    /** @param list<int> $ids @return array<int,string> emision_id => clasificación por el documento emitido (en bloques: el diseño puede ser grande) */
    private function clasificar(array $ids): array
    {
        $clases = [];
        foreach (array_chunk($ids, 200) as $bloque) {
            foreach (DB::table('cf_emisiones')->whereIn('id', $bloque)->get(['id', 'diseno_snapshot']) as $e) {
                $clases[(int) $e->id] = ClasificacionEmision::deDiseno($e->diseno_snapshot);
            }
        }

        return $clases;
    }

    /** @param array<string,mixed> $r */
    private function resumenModerno(Collection $filas): array
    {
        $vigentes = $filas->where('estado', 'vigente');
        $fechas = $vigentes->flatMap(fn ($r) => array_filter([$r['descargas']['vigente']['primera'], $r['descargas']['vigente']['ultima']]));

        return [
            'participaciones' => $filas->count(),
            'vigentes' => $vigentes->count(),
            'con_descarga' => $vigentes->where('descargado', true)->count(),
            'pendientes' => $vigentes->where('descargado', false)->count(),
            'revocadas' => $filas->where('estado', 'revocado')->count(),
            'solicitudes_vigentes' => $vigentes->sum(fn ($r) => $r['descargas']['vigente']['solicitudes']),
            'solicitudes_anteriores' => $filas->sum(fn ($r) => $r['descargas']['anteriores']['solicitudes']),
            'con_descarga_anterior' => $filas->filter(fn ($r) => $r['descargas']['anteriores']['solicitudes'] > 0)->count(),
            'primera' => $fechas->min(),
            'ultima' => $fechas->max(),
            'reintentos' => $filas->sum('reintentos'),
        ];
    }

    // ── Históricos ────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function historicos(EventoCertificacion $evento): array
    {
        $filas = DB::table('cf_certificados_legado')->where('evento_id', $evento->id)->orderBy('id')
            ->get(['id', 'nombre_completo', 'documento', 'documento_clave', 'estado', 'conciliacion_estado', 'grupo_duplicado', 'reemplazado_por_emision_id', 'codigo_legado']);
        if ($filas->isEmpty()) {
            return [];
        }
        $descargas = $this->descargasPor('certificado_legado_id', $filas->pluck('id')->all());
        // Emisión de reemplazo → participación moderna (para enlazar las dos filas).
        $participacion = DB::table('cf_emisiones')->whereIn('id', $filas->pluck('reemplazado_por_emision_id')->filter()->values()->all() ?: [0])->pluck('participante_id', 'id');

        $salida = [];
        foreach ($filas->groupBy(fn ($c) => $c->grupo_duplicado !== null && $c->grupo_duplicado !== '' ? 'g:'.$c->grupo_duplicado : 'id:'.$c->id) as $grupo) {
            // Raíz del certificado lógico: la fila que lleva el reemplazo; si no, una no consolidada como duplicado; si no, la de menor id.
            $raiz = $grupo->first(fn ($c) => $c->reemplazado_por_emision_id !== null)
                ?? $grupo->first(fn ($c) => $c->conciliacion_estado !== CertificadoLegado::CONCILIACION_DUPLICADO_CONSOLIDADO)
                ?? $grupo->first();
            $todas = $grupo->flatMap(fn ($c) => $descargas[$c->id] ?? [])->sortBy('t')->values();
            $cf = $this->metricas($todas->where('origen', 'credential_flow')->values()->all());
            $imp = $this->metricas($todas->where('origen', '!=', 'credential_flow')->values()->all());
            $estado = $raiz->reemplazado_por_emision_id !== null || $raiz->estado === CertificadoLegado::ESTADO_REEMPLAZADO ? 'reemplazado'
                : ($raiz->estado === CertificadoLegado::ESTADO_VIGENTE ? 'vigente' : 'revocado');

            $salida[] = [
                'tipo' => 'historico',
                'clave' => 'h:'.$raiz->id,
                'certificado_id' => (int) $raiz->id,
                'nombre' => (string) $raiz->nombre_completo,
                'documento' => (string) ($raiz->documento ?? ''),
                'modalidad' => null,
                'base' => null,
                'clase' => ClasificacionEmision::OFICIAL,
                'estado' => $estado,
                'codigo' => $raiz->codigo_legado,
                'filas' => $grupo->count(),
                'descargado' => $cf['solicitudes'] + $imp['solicitudes'] > 0,
                'descargas' => ['credential_flow' => $cf, 'importadas' => $imp],
                'reintentos' => $this->reintentos($grupo->map(fn ($c) => $descargas[$c->id] ?? [])->all()),
                'reemplazado_por' => $raiz->reemplazado_por_emision_id === null ? null
                    : ['emision_id' => (int) $raiz->reemplazado_por_emision_id, 'participacion_id' => isset($participacion[$raiz->reemplazado_por_emision_id]) ? (int) $participacion[$raiz->reemplazado_por_emision_id] : null],
                '_modalidad' => '',
                '_base' => 0,
                '_nombre' => NombreConservador::normalizar((string) $raiz->nombre_completo),
                '_documento_clave' => (string) $raiz->documento_clave,
                '_fechas' => $todas->pluck('t')->all(),
                '_clasificacion_distinta' => 0,
            ];
        }

        return $salida;
    }

    private function resumenHistorico(Collection $filas): array
    {
        $vigentes = $filas->where('estado', 'vigente');
        $cf = fn ($r) => $r['descargas']['credential_flow'];
        $imp = fn ($r) => $r['descargas']['importadas'];
        $fechasCf = $filas->flatMap(fn ($r) => array_filter([$cf($r)['primera'], $cf($r)['ultima']]));
        $fechasImp = $filas->flatMap(fn ($r) => array_filter([$imp($r)['primera'], $imp($r)['ultima']]));

        return [
            'certificados' => $filas->count(),
            'vigentes' => $vigentes->count(),
            'reemplazados' => $filas->where('estado', 'reemplazado')->count(),
            'revocados' => $filas->where('estado', 'revocado')->count(),
            'con_descarga' => $vigentes->where('descargado', true)->count(),
            'con_descarga_credential_flow' => $vigentes->filter(fn ($r) => $cf($r)['solicitudes'] > 0)->count(),
            'con_descarga_importada' => $vigentes->filter(fn ($r) => $imp($r)['solicitudes'] > 0)->count(),
            'pendientes' => $vigentes->where('descargado', false)->count(),
            'solicitudes_credential_flow' => $filas->sum(fn ($r) => $cf($r)['solicitudes']),
            'solicitudes_importadas' => $filas->sum(fn ($r) => $imp($r)['solicitudes']),
            'primera_credential_flow' => $fechasCf->min(),
            'ultima_credential_flow' => $fechasCf->max(),
            'primera_importada' => $fechasImp->min(),
            'ultima_importada' => $fechasImp->max(),
            'reintentos' => $filas->sum('reintentos'),
        ];
    }

    // ── Descargas ─────────────────────────────────────────────────────────────

    /**
     * Filas de cf_descargas agrupadas por `$columna` (emision_id o certificado_legado_id), ordenadas por fecha. Sin datos personales: fecha, origen y el HMAC
     * de la IP (solo para detectar reintentos; nunca sale hacia la pantalla).
     *
     * @param  list<int>  $ids
     * @return array<int, list<array{t:string, origen:string, ip:?string}>>
     */
    private function descargasPor(string $columna, array $ids): array
    {
        $por = [];
        foreach (array_chunk($ids, self::BLOQUE) as $bloque) {
            foreach (DB::table('cf_descargas')->whereIn($columna, $bloque)->orderBy('descargado_at')->orderBy('id')->get([$columna.' as rid', 'origen', 'descargado_at', 'ip_hash']) as $d) {
                $por[(int) $d->rid][] = ['t' => (string) $d->descargado_at, 'origen' => (string) $d->origen, 'ip' => $d->ip_hash];
            }
        }

        return $por;
    }

    /** @param list<array{t:string}> $filas @return array{solicitudes:int, primera:?string, ultima:?string} */
    private function metricas(array $filas): array
    {
        if ($filas === []) {
            return $this->vacio();
        }
        $t = array_column($filas, 't');

        return ['solicitudes' => count($filas), 'primera' => min($t), 'ultima' => max($t)];
    }

    /** @return array{solicitudes:int, primera:null, ultima:null} */
    private function vacio(): array
    {
        return ['solicitudes' => 0, 'primera' => null, 'ultima' => null];
    }

    /**
     * Posibles reintentos: dentro de CADA certificado (emisión o fila histórica), descargas de Credential Flow desde la misma IP a menos de
     * REINTENTO_SEGUNDOS de la anterior. Indicador secundario: no cambia ningún conteo.
     *
     * @param  list<list<array{t:string, origen:string, ip:?string}>>  $porCertificado
     */
    private function reintentos(array $porCertificado): int
    {
        $n = 0;
        foreach ($porCertificado as $filas) {
            $previa = null;
            foreach ($filas as $d) {
                if ($d['origen'] !== 'credential_flow' || $d['ip'] === null) {
                    continue;
                }
                if ($previa !== null && $previa['ip'] === $d['ip'] && strtotime($d['t']) - strtotime($previa['t']) < self::REINTENTO_SEGUNDOS) {
                    $n++;
                }
                $previa = $d;
            }
        }

        return $n;
    }
}
