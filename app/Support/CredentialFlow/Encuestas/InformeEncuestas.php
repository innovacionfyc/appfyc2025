<?php

namespace App\Support\CredentialFlow\Encuestas;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Informes de SOLO LECTURA de las encuestas (históricas y modernas, de cualquier versión), agrupadas por EVENTO REAL. Todo son consultas agregadas en la
 * base de datos: nunca se cargan las respuestas en memoria y nunca se devuelve un documento, un correo, un HMAC ni un identificador personal.
 *
 * MÉTRICAS (definiciones explícitas):
 *   - «Respuestas»: registros de `cf_encuestas_respuestas` (los duplicados históricos NO se eliminan ni se fusionan).
 *   - «Participaciones únicas»: personas distintas por evento (evento + identidad HMAC); es lo que de verdad cuenta como una persona evaluando un evento.
 *   - Porcentajes por pregunta: SIEMPRE sobre «respondidas» (respuestas con contenido real en esa pregunta); el denominador se informa.
 *   - Participación sobre personas elegibles: NO DISPONIBLE (no hay un denominador fiable para todos los eventos).
 *
 * Las respuestas de eventos que ya no existen (`evento_id` NULL) se informan en una categoría propia y nunca se asignan a otro evento. Las versiones con
 * estructuras distintas nunca se fusionan: cada versión se presenta con sus propias preguntas.
 */
class InformeEncuestas
{
    public const SIN_EVENTO = 'sin-evento';

    public const POR_PAGINA = 20;

    public const MAX_FILAS_EXPORTACION = 100000;

    public const ORIGENES = ['legado', 'credential_flow'];

    /** Texto de una respuesta que no es solo espacios/saltos/tabulaciones (portable entre MySQL y SQLite). */
    public const CON_CONTENIDO = "LENGTH(TRIM(REPLACE(REPLACE(REPLACE(d.valor_texto, CHAR(10), ''), CHAR(13), ''), CHAR(9), ''))) > 0";

    /** Respuesta con contenido real: una opción elegida, un valor de escala o un texto que no es solo espacios. */
    private const RESPONDIDA = "(d.opcion_id IS NOT NULL OR d.valor_numero IS NOT NULL OR LENGTH(TRIM(REPLACE(REPLACE(REPLACE(COALESCE(d.valor_texto, ''), CHAR(10), ''), CHAR(13), ''), CHAR(9), ''))) > 0)";

    /**
     * Normaliza los filtros recibidos: lo inválido se descarta (nunca llega a una consulta).
     *
     * @param  array<string,mixed>  $in
     * @return array{evento:int|string|null,q:?string,anio:?int,evento_desde:?string,evento_hasta:?string,resp_desde:?string,resp_hasta:?string,version:?int,origen:?string}
     */
    public static function filtros(array $in): array
    {
        $texto = fn ($v) => is_string($v) ? trim($v) : null;
        $fecha = function ($v) {
            $v = is_string($v) ? trim($v) : '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
                return null;
            }
            try {
                $d = Carbon::createFromFormat('!Y-m-d', $v);
            } catch (\Throwable) {
                return null;
            }

            return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
        };
        $entero = fn ($v, int $min, int $max) => (is_string($v) || is_int($v)) && preg_match('/^\d{1,10}$/', (string) $v) === 1 && (int) $v >= $min && (int) $v <= $max ? (int) $v : null;

        $evento = $texto($in['evento'] ?? null) ?? (is_int($in['evento'] ?? null) ? (string) $in['evento'] : null);
        $origen = $texto($in['origen'] ?? null);
        $q = $texto($in['q'] ?? null);

        return [
            'evento' => $evento === self::SIN_EVENTO ? self::SIN_EVENTO : ($evento !== null ? $entero($evento, 1, PHP_INT_MAX) : null),
            'q' => $q === null || $q === '' ? null : mb_substr($q, 0, 80),
            'anio' => $entero($in['anio'] ?? null, 1990, 2100),
            'evento_desde' => $fecha($in['evento_desde'] ?? null),
            'evento_hasta' => $fecha($in['evento_hasta'] ?? null),
            'resp_desde' => $fecha($in['resp_desde'] ?? null),
            'resp_hasta' => $fecha($in['resp_hasta'] ?? null),
            'version' => $entero($in['version'] ?? null, 1, PHP_INT_MAX),
            'origen' => in_array($origen, self::ORIGENES, true) ? $origen : null,
        ];
    }

    /**
     * Base de TODAS las consultas: respuestas + evento (si existe) + versión, con los filtros aplicados. Los filtros de fecha y año del EVENTO excluyen las
     * respuestas sin evento (no tienen fecha de evento); los de fecha de RESPUESTA se aplican a `completada_at`.
     */
    public function base(array $f): Builder
    {
        $q = DB::table('cf_encuestas_respuestas as r')->leftJoin('cf_eventos as e', 'e.id', '=', 'r.evento_id')->join('cf_encuestas_versiones as v', 'v.id', '=', 'r.version_id');

        if ($f['evento'] === self::SIN_EVENTO) {
            $q->whereNull('r.evento_id');
        } elseif ($f['evento'] !== null) {
            $q->where('r.evento_id', (int) $f['evento']);
        }
        if ($f['q'] !== null) {
            $q->where('e.nombre', 'like', '%'.addcslashes($f['q'], '%_\\').'%');
        }
        if ($f['anio'] !== null) {
            $q->where('e.anio', $f['anio']);
        }
        if ($f['evento_desde'] !== null) {
            $q->where('e.fecha_inicio', '>=', $f['evento_desde']);
        }
        if ($f['evento_hasta'] !== null) {
            $q->where('e.fecha_inicio', '<=', $f['evento_hasta']);
        }
        if ($f['resp_desde'] !== null) {
            $q->where('r.completada_at', '>=', $f['resp_desde'].' 00:00:00');
        }
        if ($f['resp_hasta'] !== null) {
            $q->where('r.completada_at', '<=', $f['resp_hasta'].' 23:59:59');
        }
        if ($f['version'] !== null) {
            $q->where('r.version_id', $f['version']);
        }
        if ($f['origen'] !== null) {
            $q->where('r.origen', $f['origen']);
        }

        return $q;
    }

    /** Opciones de los selectores de filtros (solo lo que tiene respuestas). */
    public function opciones(): array
    {
        $anios = DB::table('cf_eventos')->whereIn('id', DB::table('cf_encuestas_respuestas')->whereNotNull('evento_id')->select('evento_id'))->whereNotNull('anio')
            ->distinct()->orderByDesc('anio')->pluck('anio')->map(fn ($a) => (int) $a)->all();
        $versiones = DB::table('cf_encuestas_versiones')->orderBy('numero')->get(['id', 'numero', 'titulo'])
            ->map(fn ($v) => ['id' => (int) $v->id, 'numero' => (int) $v->numero, 'titulo' => $v->titulo])->all();

        return ['anios' => $anios, 'versiones' => $versiones];
    }

    /** @return array<string,mixed> */
    public function resumen(array $f): array
    {
        $b = fn () => $this->base($f);
        $total = $b()->count();
        $unicas = DB::query()->fromSub($b()->select('r.evento_id', 'r.documento_hash')->distinct(), 't')->count();
        $mes = DB::getDriverName() === 'sqlite' ? "strftime('%Y-%m', r.completada_at)" : "DATE_FORMAT(r.completada_at, '%Y-%m')";

        return [
            'respuestas' => $total,
            'participaciones_unicas' => $unicas,
            'eventos_evaluados' => (int) $b()->whereNotNull('r.evento_id')->distinct()->count('r.evento_id'),
            'sin_evento' => $b()->whereNull('r.evento_id')->count(),
            'sin_vinculo' => $b()->whereNotNull('r.evento_id')->whereIn('r.clasificacion', ['participante_ambiguo', 'participante_no_encontrado'])->count(),
            'por_origen' => $b()->groupBy('r.origen')->select('r.origen as k', DB::raw('count(*) as n'))->pluck('n', 'k')->map(fn ($n) => (int) $n)->all(),
            'por_version' => $b()->groupBy('v.id', 'v.numero', 'v.titulo')->orderBy('v.numero')->select('v.id', 'v.numero', 'v.titulo', DB::raw('count(*) as n'))->get()
                ->map(fn ($v) => ['id' => (int) $v->id, 'numero' => (int) $v->numero, 'titulo' => $v->titulo, 'respuestas' => (int) $v->n])->all(),
            'por_anio' => $b()->whereNotNull('r.evento_id')->groupBy('e.anio')->orderBy('e.anio')->select('e.anio as anio', DB::raw('count(*) as n'))->get()
                ->map(fn ($a) => ['anio' => $a->anio === null ? null : (int) $a->anio, 'respuestas' => (int) $a->n])->all(),
            'por_mes' => $b()->groupBy(DB::raw($mes))->orderBy(DB::raw($mes))->select(DB::raw($mes.' as mes'), DB::raw('count(*) as n'))->get()
                ->map(fn ($m) => ['mes' => $m->mes, 'respuestas' => (int) $m->n])->all(),
            'desde' => $b()->min('r.completada_at'),
            'hasta' => $b()->max('r.completada_at'),
            // No hay un denominador fiable de personas elegibles para todos los eventos: la tasa de participación NO se calcula.
            'participacion' => ['disponible' => false, 'motivo' => 'No existe un denominador verificable de personas elegibles para todos los eventos.'],
        ];
    }

    /** Lista paginada de eventos con respuestas (solo conteos). */
    public function eventos(array $f, int $pagina = 1): LengthAwarePaginator
    {
        return $this->base($f)->whereNotNull('r.evento_id')->groupBy('r.evento_id', 'e.nombre', 'e.anio', 'e.fecha_inicio')
            ->select('r.evento_id as id', 'e.nombre', 'e.anio', 'e.fecha_inicio', DB::raw('count(*) as respuestas'), DB::raw('count(distinct r.documento_hash) as personas'),
                DB::raw('count(distinct r.version_id) as versiones'), DB::raw('min(r.completada_at) as primera'), DB::raw('max(r.completada_at) as ultima'))
            ->orderByDesc('respuestas')->orderBy('e.nombre')
            ->paginate(self::POR_PAGINA, ['*'], 'page', $pagina)
            ->through(fn ($e) => [
                'id' => (int) $e->id, 'nombre' => $e->nombre, 'anio' => $e->anio === null ? null : (int) $e->anio, 'fecha_inicio' => $e->fecha_inicio,
                'respuestas' => (int) $e->respuestas, 'personas' => (int) $e->personas, 'versiones' => (int) $e->versiones, 'primera' => $e->primera, 'ultima' => $e->ultima,
            ]);
    }

    /** Datos del evento (o de la categoría «eventos no disponibles»). @return array<string,mixed>|null */
    public function evento(int|string $evento): ?array
    {
        if ($evento === self::SIN_EVENTO) {
            return ['id' => null, 'nombre' => 'Eventos no disponibles', 'anio' => null, 'fecha_inicio' => null, 'fecha_fin' => null, 'no_disponible' => true];
        }
        $e = DB::table('cf_eventos')->where('id', (int) $evento)->first(['id', 'nombre', 'anio', 'fecha_inicio', 'fecha_fin']);

        return $e === null ? null : ['id' => (int) $e->id, 'nombre' => $e->nombre, 'anio' => $e->anio === null ? null : (int) $e->anio, 'fecha_inicio' => $e->fecha_inicio, 'fecha_fin' => $e->fecha_fin, 'no_disponible' => false];
    }

    /**
     * Resultados por versión y por pregunta (sin fusionar versiones). Cada pregunta informa sus respondidas, omitidas y el denominador de los porcentajes.
     *
     * @return list<array<string,mixed>>
     */
    public function resultados(array $f): array
    {
        $versiones = $this->base($f)->groupBy('v.id', 'v.numero', 'v.titulo')->orderBy('v.numero')
            ->select('v.id', 'v.numero', 'v.titulo', DB::raw('count(*) as respuestas'), DB::raw('count(distinct r.documento_hash) as personas'), DB::raw('min(r.completada_at) as desde'), DB::raw('max(r.completada_at) as hasta'))->get();
        if ($versiones->isEmpty()) {
            return [];
        }
        $det = fn () => $this->base($f)->join('cf_encuestas_respuestas_detalle as d', 'd.respuesta_id', '=', 'r.id');
        $preguntas = DB::table('cf_encuestas_preguntas')->whereIn('version_id', $versiones->pluck('id'))->orderBy('orden')->get()->groupBy('version_id');
        $opciones = DB::table('cf_encuestas_opciones')->whereIn('pregunta_id', $preguntas->flatten()->pluck('id'))->orderBy('orden')->get()->groupBy('pregunta_id');

        $respondidas = $det()->whereNotNull('d.pregunta_id')->whereRaw(self::RESPONDIDA)->groupBy('d.pregunta_id')->select('d.pregunta_id', DB::raw('count(distinct d.respuesta_id) as n'))->pluck('n', 'pregunta_id');
        $porOpcion = $det()->whereNotNull('d.opcion_id')->groupBy('d.opcion_id')->select('d.opcion_id', DB::raw('count(*) as n'))->pluck('n', 'opcion_id');
        $escalas = [];
        foreach ($det()->whereNotNull('d.valor_numero')->groupBy('d.pregunta_id', 'd.valor_numero')->select('d.pregunta_id', 'd.valor_numero', DB::raw('count(*) as n'))->get() as $x) {
            $escalas[$x->pregunta_id][] = ['valor' => (float) $x->valor_numero, 'conteo' => (int) $x->n];
        }

        $out = [];
        foreach ($versiones as $v) {
            $total = (int) $v->respuestas;
            $out[] = [
                'id' => (int) $v->id, 'numero' => (int) $v->numero, 'titulo' => $v->titulo, 'respuestas' => $total, 'personas' => (int) $v->personas, 'desde' => $v->desde, 'hasta' => $v->hasta,
                'preguntas' => ($preguntas[$v->id] ?? collect())->map(function ($p) use ($respondidas, $porOpcion, $opciones, $escalas, $total) {
                    $r = (int) ($respondidas[$p->id] ?? 0);
                    $item = [
                        'id' => (int) $p->id, 'clave' => $p->clave, 'orden' => (int) $p->orden, 'texto' => $p->texto, 'tipo' => $p->tipo, 'activa' => (bool) $p->activa,
                        'respondidas' => $r, 'omitidas' => max(0, $total - $r), 'base_porcentaje' => $r, 'opciones' => [], 'escala' => null, 'texto_libre' => null,
                    ];
                    if ($p->tipo === 'texto') {
                        $item['texto_libre'] = ['con_contenido' => $r, 'sin_contenido' => max(0, $total - $r)];
                    } elseif ($p->tipo === 'escala') {
                        $dist = collect($escalas[$p->id] ?? [])->sortBy('valor')->values();
                        $n = $dist->sum('conteo');
                        $item['escala'] = ['distribucion' => $dist->all(), 'promedio' => $n > 0 ? round($dist->sum(fn ($d) => $d['valor'] * $d['conteo']) / $n, 2) : null];
                    } else {
                        $item['opciones'] = ($opciones[$p->id] ?? collect())->map(function ($o) use ($porOpcion, $r) {
                            $c = (int) ($porOpcion[$o->id] ?? 0);

                            return ['id' => (int) $o->id, 'valor' => $o->valor, 'etiqueta' => $o->etiqueta, 'conteo' => $c, 'porcentaje' => $r > 0 ? round($c * 100 / $r, 1) : null];
                        })->values()->all();
                    }

                    return $item;
                })->values()->all(),
            ];
        }

        return $out;
    }

    /** Comentarios completos (texto libre): SOLO para la vista restringida. Nunca incluye documento, correo ni HMAC. */
    public function comentarios(array $f, int $pagina = 1): LengthAwarePaginator
    {
        return $this->base($f)->join('cf_encuestas_respuestas_detalle as d', 'd.respuesta_id', '=', 'r.id')->join('cf_encuestas_preguntas as p', 'p.id', '=', 'd.pregunta_id')
            ->where('p.tipo', 'texto')->whereRaw(self::CON_CONTENIDO)->orderByDesc('r.completada_at')->orderByDesc('d.id')
            ->select('d.id', 'r.completada_at', 'e.nombre as evento', 'v.numero as version', 'p.clave', 'p.texto as pregunta', 'd.valor_texto as comentario')
            ->paginate(self::POR_PAGINA, ['*'], 'page', $pagina)
            ->through(fn ($c) => ['id' => (int) $c->id, 'fecha' => $c->completada_at, 'evento' => $c->evento ?? 'Evento no disponible', 'version' => (int) $c->version, 'clave' => $c->clave, 'pregunta' => $c->pregunta, 'comentario' => $c->comentario]);
    }

    // ── Conjuntos de filas para exportar (sin cargar todo en memoria) ─────────────────────────────────────

    /** @return \Generator<int,array<int,mixed>> una fila por evento (y una final para los eventos no disponibles). */
    public function filasResumenEventos(array $f): \Generator
    {
        $q = $this->base($f)->whereNotNull('r.evento_id')->groupBy('r.evento_id', 'e.nombre', 'e.anio', 'e.fecha_inicio', 'e.fecha_fin')
            ->select('e.nombre', 'e.anio', 'e.fecha_inicio', 'e.fecha_fin', DB::raw('count(*) as respuestas'), DB::raw('count(distinct r.documento_hash) as personas'),
                DB::raw('count(distinct r.version_id) as versiones'), DB::raw('sum(case when r.origen = \'legado\' then 1 else 0 end) as historicas'), DB::raw('min(r.completada_at) as primera'), DB::raw('max(r.completada_at) as ultima'))
            ->orderBy('e.nombre');
        foreach ($q->cursor() as $e) {
            yield [$e->nombre, $e->anio === null ? null : (int) $e->anio, $e->fecha_inicio, $e->fecha_fin, (int) $e->respuestas, (int) $e->personas, (int) $e->versiones, (int) $e->historicas,
                (int) $e->respuestas - (int) $e->historicas, $e->primera, $e->ultima];
        }
        $s = $this->base($f)->whereNull('r.evento_id')->select(DB::raw('count(*) as respuestas'), DB::raw('count(distinct r.documento_hash) as personas'), DB::raw('count(distinct r.version_id) as versiones'),
            DB::raw('sum(case when r.origen = \'legado\' then 1 else 0 end) as historicas'), DB::raw('min(r.completada_at) as primera'), DB::raw('max(r.completada_at) as ultima'))->first();
        if ($s !== null && (int) $s->respuestas > 0) {
            yield ['Eventos no disponibles (evento eliminado del sistema anterior)', null, null, null, (int) $s->respuestas, (int) $s->personas, (int) $s->versiones, (int) $s->historicas, (int) $s->respuestas - (int) $s->historicas, $s->primera, $s->ultima];
        }
    }

    /** @return \Generator<int,array<int,mixed>> una fila por versión y pregunta (global, con los filtros aplicados). */
    public function filasPorPregunta(array $f): \Generator
    {
        foreach ($this->resultados($f) as $v) {
            foreach ($v['preguntas'] as $p) {
                yield [$v['numero'], $v['titulo'], $p['orden'], $p['clave'], $p['texto'], $p['tipo'], $v['respuestas'], $p['respondidas'], $p['omitidas'],
                    $v['respuestas'] > 0 ? round($p['respondidas'] * 100 / $v['respuestas'], 1) : null];
            }
        }
    }

    public function contarFilasDistribucion(array $f): int
    {
        return (int) DB::query()->fromSub($this->consultaDistribucion($f)->select('r.evento_id', 'v.id as v', 'd.pregunta_id', 'd.opcion_id')->distinct(), 't')->count();
    }

    /** @return \Generator<int,array<int,mixed>> evento × versión × pregunta × opción, con el porcentaje sobre las respondidas de esa pregunta en ese evento. */
    public function filasDistribucion(array $f): \Generator
    {
        $den = [];
        $q = $this->base($f)->join('cf_encuestas_respuestas_detalle as d', 'd.respuesta_id', '=', 'r.id')->whereNotNull('d.pregunta_id')->whereRaw(self::RESPONDIDA)
            ->groupBy('r.evento_id', 'd.pregunta_id')->select('r.evento_id', 'd.pregunta_id', DB::raw('count(distinct d.respuesta_id) as n'));
        foreach ($q->cursor() as $x) {
            $den[($x->evento_id ?? 'x').'|'.$x->pregunta_id] = (int) $x->n;
        }
        $filas = $this->consultaDistribucion($f)
            ->groupBy('r.evento_id', 'e.nombre', 'e.anio', 'v.numero', 'p.id', 'p.orden', 'p.clave', 'p.texto', 'o.orden', 'o.valor', 'o.etiqueta')
            ->orderBy('e.nombre')->orderBy('v.numero')->orderBy('p.orden')->orderBy('o.orden')
            ->select('r.evento_id', 'e.nombre as evento', 'e.anio', 'v.numero as version', 'p.id as pregunta_id', 'p.orden', 'p.clave', 'p.texto as pregunta', 'o.valor', 'o.etiqueta', DB::raw('count(*) as n'));
        foreach ($filas->cursor() as $x) {
            $base = $den[($x->evento_id ?? 'x').'|'.$x->pregunta_id] ?? 0;
            yield [$x->evento ?? 'Evento no disponible', $x->anio === null ? null : (int) $x->anio, (int) $x->version, (int) $x->orden, $x->clave, $x->pregunta, $x->etiqueta ?: $x->valor,
                (int) $x->n, $base, $base > 0 ? round((int) $x->n * 100 / $base, 1) : null];
        }
    }

    private function consultaDistribucion(array $f): Builder
    {
        return $this->base($f)->join('cf_encuestas_respuestas_detalle as d', 'd.respuesta_id', '=', 'r.id')->join('cf_encuestas_preguntas as p', 'p.id', '=', 'd.pregunta_id')
            ->join('cf_encuestas_opciones as o', 'o.id', '=', 'd.opcion_id');
    }
}
