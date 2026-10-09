<?php

namespace App\Support\CredentialFlow\Historico;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de SOLO LECTURA de las encuestas HISTÓRICAS (Fase 8) para el administrador: resumen, estructura por versión, conteos por
 * pregunta/opción y filtro por evento. Todas las cifras salen de consultas (nada hardcodeado).
 *
 * PRIVACIDAD: nunca devuelve texto libre (pregunta5…8), nombres, documentos, correos ni ids personales. De las preguntas de texto solo se
 * informa CUÁNTAS personas contestaron, cuántas no y cuántos valores distintos hay. Los valores de las opciones (Excelente, Bueno…) son
 * configuración de la encuesta, no datos personales, y se muestran tal como se contestaron (las redacciones antiguas NO se corrigen).
 *
 * Filtro por evento (`$filtro`): null = todos · un id de evento · `self::NO_DISPONIBLE` = respuestas cuyo evento ya no existe en el sistema
 * (se conserva solo el id viejo; no se inventa ningún evento).
 */
final class ConsultaEncuestas
{
    public const NO_DISPONIBLE = 'no_disponible';

    /** Columnas del sistema viejo que existieron pero nunca tuvieron respuestas y no son preguntas visibles del modelo final. */
    private const COLUMNAS_SIN_PREGUNTA = ['pregunta9', 'justificacion_pregunta1'];

    /** Texto de una respuesta que no es solo espacios/saltos/tabulaciones (portable entre MySQL y SQLite). */
    private const CON_CONTENIDO = "LENGTH(TRIM(REPLACE(REPLACE(REPLACE(valor_texto, CHAR(10), ''), CHAR(13), ''), CHAR(9), ''))) > 0";

    /** @return array<string,mixed> */
    public function resumen(): array
    {
        $enc = DB::table('cf_encuestas')->where('origen', 'legado')->first();
        if ($enc === null) {
            return ['hay' => false];
        }
        $resp = fn () => DB::table('cf_encuestas_respuestas')->where('origen', 'legado');
        $versiones = fn () => DB::table('cf_encuestas_versiones')->whereIn('encuesta_id', DB::table('cf_encuestas')->where('origen', 'legado')->select('id'));
        $preguntas = fn () => DB::table('cf_encuestas_preguntas')->whereIn('version_id', $versiones()->select('id'));

        return [
            'hay' => true,
            'nombre' => $enc->nombre,
            'respuestas' => $resp()->count(),
            'versiones' => $versiones()->count(),
            'preguntas' => $preguntas()->count(),
            'opciones' => DB::table('cf_encuestas_opciones')->whereIn('pregunta_id', $preguntas()->select('id'))->count(),
            'detalles' => DB::table('cf_encuestas_respuestas_detalle')->whereIn('respuesta_id', $resp()->select('id'))->count(),
            'clasificacion' => $resp()->selectRaw('clasificacion c, count(*) n')->groupBy('clasificacion')->pluck('n', 'c')->map(fn ($n) => (int) $n)->all(),
            'eventos_con_respuestas' => $resp()->whereNotNull('evento_id')->distinct()->count('evento_id'),
            'eventos_no_disponibles' => $resp()->whereNotNull('old_evento_id')->distinct()->count('old_evento_id'),
            'respuestas_evento_no_disponible' => $resp()->whereNull('evento_id')->count(),
            'desde' => $resp()->min('completada_at'),
            'hasta' => $resp()->max('completada_at'),
        ];
    }

    /** Aplica el filtro de evento a una consulta sobre cf_encuestas_respuestas. */
    private function filtrar(Builder $q, int|string|null $filtro): Builder
    {
        return match (true) {
            $filtro === null => $q,
            $filtro === self::NO_DISPONIBLE => $q->whereNull('evento_id'),
            default => $q->where('evento_id', (int) $filtro),
        };
    }

    /**
     * Estructura y conteos de cada versión histórica (con el filtro de evento aplicado a los conteos).
     *
     * @return list<array<string,mixed>>
     */
    public function versiones(int|string|null $filtro = null): array
    {
        $versiones = DB::table('cf_encuestas_versiones as v')->join('cf_encuestas as e', 'e.id', '=', 'v.encuesta_id')->where('e.origen', 'legado')->orderBy('v.numero')
            ->get(['v.id', 'v.numero', 'v.titulo', 'v.activa_desde', 'v.activa_hasta']);
        $ultima = $versiones->last();
        $opcionesActuales = [];   // clave => [valor => true] de la última versión: para señalar las redacciones antiguas
        if ($ultima !== null) {
            foreach (DB::table('cf_encuestas_opciones as o')->join('cf_encuestas_preguntas as p', 'p.id', '=', 'o.pregunta_id')->where('p.version_id', $ultima->id)->get(['p.clave', 'o.valor']) as $o) {
                $opcionesActuales[$o->clave][$o->valor] = true;
            }
        }

        $out = [];
        foreach ($versiones as $v) {
            $respuestas = fn () => $this->filtrar(DB::table('cf_encuestas_respuestas')->where('version_id', $v->id), $filtro);
            $total = $respuestas()->count();
            $ids = fn () => $respuestas()->select('id');
            $porPregunta = DB::table('cf_encuestas_respuestas_detalle')->whereIn('respuesta_id', $ids())->selectRaw('pregunta_id, count(*) n, sum(CASE WHEN '.self::CON_CONTENIDO.' THEN 1 ELSE 0 END) con_contenido')->groupBy('pregunta_id')->get()->keyBy('pregunta_id');
            // Valores distintos de las preguntas de texto: solo un NÚMERO (nunca los valores).
            $distintos = DB::table('cf_encuestas_respuestas_detalle')->whereIn('respuesta_id', $ids())->whereRaw(self::CON_CONTENIDO)->selectRaw('pregunta_id, count(distinct valor_texto) n')->groupBy('pregunta_id')->pluck('n', 'pregunta_id');
            $porOpcion = DB::table('cf_encuestas_respuestas_detalle')->whereIn('respuesta_id', $ids())->whereNotNull('opcion_id')->selectRaw('opcion_id, count(*) n')->groupBy('opcion_id')->pluck('n', 'opcion_id');
            $preguntas = DB::table('cf_encuestas_preguntas')->where('version_id', $v->id)->orderBy('orden')->get();
            $opciones = DB::table('cf_encuestas_opciones')->whereIn('pregunta_id', $preguntas->pluck('id'))->orderBy('orden')->get()->groupBy('pregunta_id');

            $out[] = [
                'id' => (int) $v->id, 'numero' => (int) $v->numero, 'titulo' => $v->titulo, 'desde' => $v->activa_desde, 'hasta' => $v->activa_hasta,
                'respuestas' => $total,
                'preguntas_total' => $preguntas->count(),
                'preguntas_activas' => $preguntas->where('activa', true)->count(),
                'preguntas' => $preguntas->map(function ($p) use ($porPregunta, $porOpcion, $opciones, $distintos, $total, $opcionesActuales, $v, $ultima) {
                    $cfg = json_decode((string) $p->configuracion, true) ?? [];
                    $n = (int) ($porPregunta[$p->id]->n ?? 0);
                    $conContenido = (int) ($porPregunta[$p->id]->con_contenido ?? 0);
                    $esTexto = $p->tipo === 'texto';

                    return [
                        'clave' => $p->clave, 'orden' => (int) $p->orden, 'tipo' => $p->tipo, 'activa' => (bool) $p->activa, 'texto' => $p->texto,
                        'redaccion_recuperable' => (bool) ($cfg['redaccion_recuperable'] ?? false),
                        'contestadas' => $n, 'sin_respuesta' => max(0, $total - $n),
                        // Texto libre: SOLO números (con contenido, vacías, total, distintos); nunca el contenido.
                        'texto_libre' => $esTexto ? ['respondidas' => $conContenido, 'vacias' => max(0, $total - $conContenido), 'total' => $total, 'distintos' => (int) ($distintos[$p->id] ?? 0)] : null,
                        'opciones' => $esTexto ? [] : ($opciones[$p->id] ?? collect())->map(fn ($o) => [
                            'valor' => $o->valor, 'conteo' => (int) ($porOpcion[$o->id] ?? 0),
                            // Redacción antigua: ya no figura entre las opciones de la estructura más reciente. Se conserva SIN corregir.
                            'historica' => $ultima !== null && $v->id !== $ultima->id && ! isset($opcionesActuales[$p->clave][$o->valor]),
                        ])->values()->all(),
                    ];
                })->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * Resumen por clave de pregunta a través de las versiones (dónde aparece, cuántas contestaron y cuántas no).
     *
     * @param  list<array<string,mixed>>  $versiones  salida de versiones()
     * @return list<array<string,mixed>>
     */
    public function resumenPorClave(array $versiones): array
    {
        $claves = [];
        foreach ($versiones as $v) {
            foreach ($v['preguntas'] as $p) {
                $c = &$claves[$p['clave']];
                $c['clave'] = $p['clave'];
                $c['orden'] = $p['orden'];
                $c['tipos'][$p['tipo']] = true;
                $c['versiones'][] = $v['numero'];
                $c['contestadas'] = ($c['contestadas'] ?? 0) + $p['contestadas'];
                $c['sin_respuesta'] = ($c['sin_respuesta'] ?? 0) + $p['sin_respuesta'];
                unset($c);
            }
        }
        $todas = collect($versiones)->pluck('numero')->all();
        $out = array_map(fn ($c) => [
            'clave' => $c['clave'], 'orden' => $c['orden'], 'tipo' => implode(' / ', array_keys($c['tipos'])), 'versiones' => $c['versiones'],
            'contestadas' => $c['contestadas'], 'sin_respuesta' => $c['sin_respuesta'],
            // Pregunta que no está en todas las versiones: existió de verdad y se retiró (p. ej. pregunta8) o apareció después.
            'parcial' => count($c['versiones']) !== count($todas),
        ], array_values($claves));
        usort($out, fn ($a, $b) => $a['orden'] <=> $b['orden']);

        return $out;
    }

    /**
     * Columnas del sistema viejo SIN pregunta en el modelo final (pregunta9, justificacion_pregunta1): solo una nota técnica con los
     * conteos que dejó la migración. Si algún día tuvieran respuestas, se conservaron por clave histórica (sin pregunta).
     *
     * @return list<array<string,mixed>>
     */
    public function columnasSinPregunta(): array
    {
        $corrida = DB::table('cf_migraciones_corridas')->where('tipo', 'legado_encuestas')->where('estado', 'completada')->orderByDesc('id')->first();
        $t = $corrida === null ? [] : (json_decode((string) $corrida->totales, true) ?? []);
        $out = [];
        foreach (self::COLUMNAS_SIN_PREGUNTA as $clave) {
            $respuestas = DB::table('cf_encuestas_respuestas_detalle')->where('clave_historica', $clave)->count();
            $out[] = [
                'clave' => $clave, 'con_respuestas' => $respuestas, 'vacias_en_origen' => $t['vacias_por_clave'][$clave] ?? null,
                'pregunta_creada' => DB::table('cf_encuestas_preguntas')->where('clave', $clave)->exists(),
            ];
        }

        return $out;
    }

    /**
     * Eventos con más respuestas (solo conteos), con búsqueda opcional por nombre.
     *
     * @return list<array<string,mixed>>
     */
    public function eventos(?string $q = null, int $limite = 20): array
    {
        $esc = fn (string $s) => addcslashes($s, '%_\\');

        return DB::table('cf_encuestas_respuestas as r')->join('cf_eventos as e', 'e.id', '=', 'r.evento_id')->where('r.origen', 'legado')
            ->when($q !== null && trim($q) !== '', fn ($w) => $w->where('e.nombre', 'like', '%'.$esc(trim($q)).'%'))
            ->groupBy('e.id', 'e.nombre', 'e.anio')->orderByRaw('count(*) desc')->orderBy('e.nombre')->limit($limite)
            ->get(['e.id', 'e.nombre', 'e.anio', DB::raw('count(*) as n')])
            ->map(fn ($e) => ['id' => (int) $e->id, 'nombre' => $e->nombre, 'anio' => $e->anio === null ? null : (int) $e->anio, 'respuestas' => (int) $e->n])->all();
    }

    /** @return array{id:int,nombre:string}|null */
    public function evento(int $id): ?array
    {
        $e = DB::table('cf_eventos')->where('id', $id)->first(['id', 'nombre']);

        return $e === null ? null : ['id' => (int) $e->id, 'nombre' => $e->nombre];
    }
}
