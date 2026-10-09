<?php

namespace App\Support\CredentialFlow\Migracion;

use App\Models\CredentialFlow\MigracionCorrida;
use App\Support\CredentialFlow\Portal\Hmac;
use App\Support\CredentialFlow\StagingEv\ReporteConciliacion;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Migración de las ENCUESTAS HISTÓRICAS (Fase 8): del staging (stg_ev_encuesta, con pregunta1…9 intactas) al modelo configurable
 * (cf_encuestas*), por CORRIDA propia (tipo legado_encuestas), atómica, idempotente y con rollback técnico (RollbackEncuestas).
 *
 * El staging NO se toca. Las columnas pregunta1…pregunta9 y justificacion_pregunta1 se copian TAL CUAL (texto exacto, sin recortar ni
 * normalizar) al detalle, con `clave_historica`. No se descarta ninguna respuesta (ni las huérfanas) y no se inventa ningún certificado.
 *
 * VERSIONES (el mínimo que explica los datos reales; no se inventan por año). Evidencia en el staging de 3.079 respuestas:
 *   1 «inicial»     hasta 2022-02-17: opciones en otra redacción (Excelentes/Buenos/Malos, Muy buena…) y pregunta2/4 de Si/No; sin pregunta8.
 *   2 «intermedia»  2022-02-18 a 2025-07-29: las cuatro primeras son escalas de calificación; pregunta8 (texto) existe y se usa.
 *   3 «actual»      desde 2025-07-30: pregunta4 vuelve a Si/No; pregunta8 desaparece; es la redacción del catálogo vigente del snapshot.
 * Los cortes (2022-02-18 y 2025-07-30) son los puntos entre la última respuesta de una estructura y la primera de la siguiente; la fecha
 * real del cambio no se puede recuperar. Las opciones de cada versión son las OBSERVADAS en sus respuestas (la redacción antigua se
 * conserva). La redacción original de las preguntas de las versiones 1 y 2 NO existe en el origen: se usa un texto neutral y se deja
 * constancia (`configuracion.redaccion_recuperable=false`); nunca se inventa.
 * pregunta9 y justificacion_pregunta1 están vacías en todas las filas y no figuran en el catálogo: no se crea pregunta para ellas; si
 * alguna vez trajeran datos se conservarían como detalle sin pregunta (clave histórica).
 */
final class MigradorEncuestas
{
    public const CORTE_INTERMEDIA = '2022-02-18 00:00:00';

    public const CORTE_ACTUAL = '2025-07-30 00:00:00';

    public const NOMBRE = 'Encuesta histórica Evaluaciones';

    /** Orden de presentación de las opciones conocidas; el resto, alfabético a continuación. */
    private const ORDEN_OPCIONES = ['Excelente', 'Muy bueno', 'Bueno', 'Regular', 'Deficiente', 'Si', 'No'];

    /** pregunta_i → tipo (las cuatro primeras guardan el TEXTO de la opción; el resto es texto libre). */
    private const TIPOS = [1 => 'opcion_unica', 2 => 'opcion_unica', 3 => 'opcion_unica', 4 => 'opcion_unica', 5 => 'texto', 6 => 'texto', 7 => 'texto', 8 => 'texto'];

    private const CLAVES_VALOR = ['pregunta1', 'pregunta2', 'pregunta3', 'pregunta4', 'pregunta5', 'pregunta6', 'pregunta7', 'pregunta8', 'pregunta9', 'justificacion_pregunta1'];

    private string $ahora;

    private int $corridaId = 0;

    /** @var array<int,array<string,mixed>> */
    private array $bufferMapa = [];

    /** @var array<string,mixed> */
    private array $totales = [];

    /** @var array<string,float> */
    private array $tiempos = [];

    /** @var array<int,array<string,mixed>> */
    private array $validaciones = [];

    public function __construct(private readonly ?string $conexionStaging = null) {}

    private function stg(): ConnectionInterface
    {
        return DB::connection($this->conexionStaging);
    }

    public function ejecutar(): ResultadoMigracion
    {
        GuardiaMigracion::exigirDestinoSeguro();
        GuardiaMigracion::exigirOrigenStaging($this->conexionStaging);

        $inicio = microtime(true);
        [$snapshotSha, $huellaGlobal, $huellaDerivada] = $this->leerHuellas();

        $existente = MigracionCorrida::where('tipo', MigracionCorrida::TIPO_ENCUESTAS)->where('huella_global', $huellaGlobal)->where('huella_derivada', $huellaDerivada)->where('snapshot_sha256', $snapshotSha);
        $hecha = (clone $existente)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->orderByDesc('id')->first();
        if ($hecha !== null) {
            return new ResultadoMigracion(true, $hecha->id, $hecha->totales ?? [], "Las encuestas de ese snapshot ya están migradas (corrida #{$hecha->id}, completada). No se hizo ningún cambio.");
        }
        if ((clone $existente)->where('estado', MigracionCorrida::ESTADO_EJECUTANDO)->exists()) {
            throw new RuntimeException('Hay otra corrida de encuestas de este snapshot en estado «ejecutando». No se inicia otra.');
        }
        $otra = MigracionCorrida::where('tipo', MigracionCorrida::TIPO_ENCUESTAS)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->orderByDesc('id')->first();
        if ($otra !== null) {
            throw new RuntimeException("Ya hay una corrida de encuestas completada de OTRO snapshot (#{$otra->id}): revierte esa corrida antes de migrar otra.");
        }
        // Las respuestas se ligan a eventos y certificados ya migrados: hace falta la corrida de certificados completada.
        $certificados = MigracionCorrida::where('tipo', MigracionCorrida::TIPO_LEGADO)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->orderByDesc('id')->first();
        if ($certificados === null) {
            throw new RuntimeException('No hay una corrida de certificados completada: primero se migran eventos y certificados (credential-flow:legado:migrar).');
        }

        $corrida = MigracionCorrida::create([
            'tipo' => MigracionCorrida::TIPO_ENCUESTAS, 'snapshot_sha256' => $snapshotSha, 'huella_global' => $huellaGlobal, 'huella_derivada' => $huellaDerivada,
            'estado' => MigracionCorrida::ESTADO_EJECUTANDO, 'iniciado_at' => now(),
        ]);
        $this->corridaId = $corrida->id;
        $this->ahora = now()->format('Y-m-d H:i:s');

        try {
            DB::transaction(function () use ($inicio, $huellaGlobal, $huellaDerivada, $snapshotSha, $certificados) {
                $this->migrar($certificados->id);
                $this->totales['huellas'] = ['snapshot_sha256' => $snapshotSha, 'global' => $huellaGlobal, 'derivada' => $huellaDerivada, 'corrida_certificados' => $certificados->id];
                $this->totales['validaciones'] = $this->validaciones;
                $this->totales['tiempos_ms'] = array_map(fn ($t) => (int) round($t), $this->tiempos) + ['total' => (int) round((microtime(true) - $inicio) * 1000)];
                MigracionCorrida::whereKey($this->corridaId)->update(['estado' => MigracionCorrida::ESTADO_COMPLETADA, 'finalizado_at' => now(), 'totales' => json_encode($this->totales, JSON_UNESCAPED_UNICODE)]);
            });
        } catch (Throwable $e) {
            MigracionCorrida::whereKey($this->corridaId)->update([
                'estado' => MigracionCorrida::ESTADO_FALLIDA, 'finalizado_at' => now(), 'error_codigo' => mb_substr($e::class, 0, 120),
                'totales' => json_encode(['validaciones' => $this->validaciones], JSON_UNESCAPED_UNICODE),
            ]);

            throw $e;
        }

        return new ResultadoMigracion(false, $this->corridaId, $this->totales, "Migración de encuestas completada (corrida #{$this->corridaId}).");
    }

    /** @return array{0:string,1:string,2:string} */
    private function leerHuellas(): array
    {
        $generar = fn () => (new ReporteConciliacion)->generar();
        $r = $this->conexionStaging === null ? $generar() : DB::usingConnection($this->conexionStaging, $generar);
        if ($r['snapshot'] === null || $r['snapshot']['dump_sha256'] === null) {
            throw new RuntimeException('El staging no tiene ningún snapshot cargado.');
        }

        return [$r['snapshot']['dump_sha256'], $r['huella_contenido'], $r['huella_derivada']];
    }

    // ── Migración (dentro de la transacción) ──────────────────────────────────────────────────────────────

    private function migrar(int $corridaCertificados): void
    {
        $etapa = function (string $nombre, callable $f) {
            $t = microtime(true);
            $r = $f();
            $this->tiempos[$nombre] = (microtime(true) - $t) * 1000;

            return $r;
        };

        $filas = $etapa('leer_staging', fn () => $this->stg()->table('stg_ev_encuesta')->orderBy('fecha')->orderBy('old_id')->get()->all());
        $catalogo = $this->catalogo();

        // Versión de cada fila (por fecha) y valores observados de las preguntas de opción por versión.
        $versionDe = fn ($f) => $f->fecha >= self::CORTE_ACTUAL ? 'actual' : ($f->fecha >= self::CORTE_INTERMEDIA ? 'intermedia' : 'inicial');
        $observado = [];
        $rangos = [];
        $conteo = [];
        foreach ($filas as $f) {
            $cod = $versionDe($f);
            $conteo[$cod] = ($conteo[$cod] ?? 0) + 1;
            $rangos[$cod] = [min($rangos[$cod][0] ?? $f->fecha, $f->fecha), max($rangos[$cod][1] ?? $f->fecha, $f->fecha)];
            foreach ([1, 2, 3, 4] as $i) {
                $v = $f->{"pregunta{$i}"};
                if ($v !== null && $v !== '') {
                    $observado[$cod][$i][$v] = ($observado[$cod][$i][$v] ?? 0) + 1;
                }
            }
        }

        $estructura = $etapa('estructura', fn () => $this->crearEstructura($conteo, $rangos, $observado, $catalogo));
        $enlaces = $this->prepararEnlaces($corridaCertificados);

        $etapa('respuestas', function () use ($filas, $versionDe, $estructura, $enlaces) {
            $this->crearRespuestas($filas, $versionDe, $estructura, $enlaces);
        });
        $this->vaciarMapa();
        $this->validar();
    }

    /** @return array{preguntas:array<int,object>,opciones:array<int,array<string,string>>} catálogo vigente del snapshot */
    private function catalogo(): array
    {
        $preguntas = [];
        foreach ($this->stg()->table('stg_ev_preguntas')->get() as $p) {
            $preguntas[(int) $p->old_id] = $p;
        }

        return ['preguntas' => $preguntas, 'opciones' => $this->stg()->table('stg_ev_opciones')->get()->groupBy('old_pregunta_id')->map(fn ($g) => $g->pluck('opcion')->all())->all()];
    }

    /**
     * Crea encuesta, versiones, preguntas y opciones. Solo existen las versiones que tienen respuestas.
     *
     * @return array<string,array{id:int,preguntas:array<string,array{id:int,tipo:string,texto:string,opciones:array<string,array{id:int,etiqueta:string}>}>}>
     */
    private function crearEstructura(array $conteo, array $rangos, array $observado, array $catalogo): array
    {
        if ($conteo === []) {
            throw new RuntimeException('El staging no tiene encuestas que migrar.');
        }
        $encuestaId = DB::table('cf_encuestas')->insertGetId([
            'nombre' => self::NOMBRE, 'estado' => 'archivada', 'activa' => false, 'origen' => 'legado', 'corrida_id' => $this->corridaId, 'created_at' => $this->ahora, 'updated_at' => $this->ahora,
        ]);
        $titulos = [
            'inicial' => 'Estructura inicial (hasta febrero de 2022)', 'intermedia' => 'Estructura intermedia (febrero de 2022 a julio de 2025)', 'actual' => 'Estructura actual (desde julio de 2025)',
        ];
        $estructura = [];
        $numero = 0;
        foreach (['inicial', 'intermedia', 'actual'] as $cod) {
            if (! isset($conteo[$cod])) {
                continue;
            }
            $numero++;
            $claves = $cod === 'intermedia' ? range(1, 8) : range(1, 7);
            $reciente = $cod === 'actual';
            $planes = [];
            foreach ($claves as $i) {
                $cat = $catalogo['preguntas'][$i] ?? null;
                // Solo la estructura ACTUAL tiene redacción recuperable (el catálogo vigente); las anteriores usan un texto neutral.
                $texto = $reciente && $cat !== null && trim((string) $cat->texto) !== '' ? (string) $cat->texto : "Pregunta histórica {$i} (redacción original no recuperable)";
                $opciones = [];
                if (self::TIPOS[$i] === 'opcion_unica') {
                    $valores = array_keys($observado[$cod][$i] ?? []);
                    usort($valores, function ($a, $b) {
                        $pa = array_search($a, self::ORDEN_OPCIONES, true);
                        $pb = array_search($b, self::ORDEN_OPCIONES, true);

                        return [$pa === false ? 99 : $pa, $a] <=> [$pb === false ? 99 : $pb, $b];
                    });
                    $opciones = $valores;
                }
                $planes[$i] = ['texto' => $texto, 'recuperable' => $reciente && $cat !== null, 'catalogo' => $cat === null || $i > 7 ? null : $i, 'opciones' => $opciones];
            }
            $snapshot = [
                'codigo' => $cod, 'evidencia' => ['respuestas' => $conteo[$cod], 'primera' => $rangos[$cod][0], 'ultima' => $rangos[$cod][1], 'corte_inferior_inferido' => $cod === 'inicial' ? null : ($cod === 'intermedia' ? self::CORTE_INTERMEDIA : self::CORTE_ACTUAL)],
                'preguntas' => collect($planes)->map(fn ($p, $i) => ['clave' => "pregunta{$i}", 'tipo' => self::TIPOS[$i], 'redaccion_recuperable' => $p['recuperable'], 'opciones' => $p['opciones']])->values()->all(),
                'nota' => 'Estructura deducida de las respuestas históricas. La fecha real del cambio y la redacción original de las versiones anteriores a la actual no se pueden recuperar.',
            ];
            $versionId = DB::table('cf_encuestas_versiones')->insertGetId([
                'encuesta_id' => $encuestaId, 'numero' => $numero, 'titulo' => $titulos[$cod], 'introduccion' => null, 'obligatoria' => false, 'publicada_at' => $this->ahora,
                'activa_desde' => $rangos[$cod][0], 'activa_hasta' => $rangos[$cod][1], 'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'corrida_id' => $this->corridaId,
                'created_at' => $this->ahora, 'updated_at' => $this->ahora,
            ]);
            $this->mapa('encuesta_version', $cod, 'cf_encuestas_versiones', $versionId, 'principal', ['numero' => $numero, 'respuestas' => $conteo[$cod]]);
            $estructura[$cod] = ['id' => $versionId, 'numero' => $numero, 'preguntas' => []];
            foreach ($planes as $i => $p) {
                $preguntaId = DB::table('cf_encuestas_preguntas')->insertGetId([
                    'version_id' => $versionId, 'orden' => $i, 'clave' => "pregunta{$i}", 'texto' => $p['texto'], 'tipo' => self::TIPOS[$i], 'obligatoria' => false, 'activa' => true, 'ayuda' => null,
                    'configuracion' => json_encode(['redaccion_recuperable' => $p['recuperable'], 'catalogo_actual_old_id' => $p['catalogo'], 'nota' => $p['recuperable'] ? null : 'La redacción original no existe en el origen.'], JSON_UNESCAPED_UNICODE),
                    'created_at' => $this->ahora, 'updated_at' => $this->ahora,
                ]);
                $opciones = [];
                foreach ($p['opciones'] as $orden => $valor) {
                    $id = DB::table('cf_encuestas_opciones')->insertGetId(['pregunta_id' => $preguntaId, 'orden' => $orden + 1, 'valor' => $valor, 'etiqueta' => $valor, 'activa' => true, 'created_at' => $this->ahora, 'updated_at' => $this->ahora]);
                    $opciones[$valor] = ['id' => $id, 'etiqueta' => $valor];
                }
                $estructura[$cod]['preguntas']["pregunta{$i}"] = ['id' => $preguntaId, 'tipo' => self::TIPOS[$i], 'texto' => $p['texto'], 'opciones' => $opciones];
            }
        }
        $this->totales['encuesta_id'] = $encuestaId;
        $this->totales['versiones'] = collect($estructura)->map(fn ($v, $cod) => ['codigo' => $cod, 'numero' => $v['numero'], 'respuestas' => $conteo[$cod], 'preguntas' => count($v['preguntas']), 'opciones' => collect($v['preguntas'])->sum(fn ($p) => count($p['opciones']))])->values()->all();

        return $estructura;
    }

    /** Eventos y certificados ya migrados, para ligar las respuestas SIN inventar nada. */
    private function prepararEnlaces(int $corridaCertificados): array
    {
        $eventos = DB::table('cf_migraciones_map')->where('corrida_id', $corridaCertificados)->where('origen_tabla', 'evento')->pluck('destino_id', 'origen_id')->all();
        $porEventoDoc = [];
        foreach (DB::table('cf_certificados_legado')->where('corrida_id', $corridaCertificados)->get(['id', 'evento_id', 'documento_clave', 'conciliacion_estado']) as $c) {
            $porEventoDoc[$c->evento_id."\0".$c->documento_clave][] = $c;
        }

        return ['eventos' => $eventos, 'certificados' => $porEventoDoc];
    }

    private function crearRespuestas(array $filas, callable $versionDe, array $estructura, array $enlaces): void
    {
        $clasif = [];
        $porVersion = [];
        $detalles = [];
        $porClave = array_fill_keys(self::CLAVES_VALOR, 0);
        $soloEspacios = array_fill_keys(self::CLAVES_VALOR, 0);
        $vaciasPorClave = array_fill_keys(self::CLAVES_VALOR, 0);
        $mapeadas = 0;
        $soloClave = 0;
        $totalDetalles = 0;

        foreach ($filas as $f) {
            $cod = $versionDe($f);
            $v = $estructura[$cod];
            $eventoId = $f->evento_existe ? ($enlaces['eventos'][(string) $f->old_evento_id] ?? null) : null;
            $certId = null;
            if (! $f->evento_existe) {
                $clase = 'evento_historico_eliminado';
            } elseif ($eventoId === null) {
                $clase = 'evento_no_migrado';
            } else {
                $cands = $enlaces['certificados'][$eventoId."\0".$f->documento_clave] ?? [];
                $oks = array_values(array_filter($cands, fn ($c) => $c->conciliacion_estado === 'ok'));
                if (count($cands) === 0) {
                    $clase = 'participante_no_encontrado';
                } elseif (count($cands) === 1) {
                    $clase = 'vinculada';
                    $certId = $cands[0]->id;
                } elseif (count($oks) === 1) {
                    $clase = 'vinculada';
                    $certId = $oks[0]->id;
                } else {
                    $clase = 'participante_ambiguo';
                }
            }
            $clasif[$clase] = ($clasif[$clase] ?? 0) + 1;
            $porVersion[$v['numero']] = ($porVersion[$v['numero']] ?? 0) + 1;

            $respuestaId = DB::table('cf_encuestas_respuestas')->insertGetId([
                'version_id' => $v['id'], 'evento_id' => $eventoId, 'certificado_legado_id' => $certId, 'emision_id' => null,
                'documento_hash' => $f->documento_clave === '' ? null : Hmac::de('documento', $f->documento_clave), 'old_evento_id' => $f->evento_existe ? null : $f->old_evento_id,
                'clasificacion' => $clase, 'completada_at' => $f->fecha, 'origen' => 'legado', 'corrida_id' => $this->corridaId, 'created_at' => $this->ahora, 'updated_at' => $this->ahora,
            ]);
            $this->mapa('encuesta', (string) $f->old_id, 'cf_encuestas_respuestas', $respuestaId, 'principal', ['clasificacion' => $clase, 'version' => $v['numero']] + ($f->evento_existe ? [] : ['old_evento_id' => (int) $f->old_evento_id]));

            foreach (self::CLAVES_VALOR as $clave) {
                $valor = $f->{$clave};
                if ($valor === null || $valor === '') {
                    $vaciasPorClave[$clave]++;

                    continue;
                }
                $porClave[$clave]++;
                $totalDetalles++;
                if (trim($valor) === '') {
                    $soloEspacios[$clave]++;
                }
                $p = $v['preguntas'][$clave] ?? null;
                $op = $p !== null && $p['tipo'] === 'opcion_unica' ? ($p['opciones'][$valor] ?? null) : null;
                $p !== null ? $mapeadas++ : $soloClave++;
                $detalles[] = [
                    'respuesta_id' => $respuestaId, 'pregunta_id' => $p['id'] ?? null, 'clave_historica' => $clave, 'valor_texto' => $valor, 'valor_numero' => null, 'opcion_id' => $op['id'] ?? null,
                    'snapshot_pregunta' => json_encode($p === null ? ['clave' => $clave, 'tipo' => null, 'texto' => null, 'nota' => 'Sin pregunta configurable en esta versión.'] : ['clave' => $clave, 'tipo' => $p['tipo'], 'texto' => $p['texto']], JSON_UNESCAPED_UNICODE),
                    'snapshot_opcion' => $op['etiqueta'] ?? null, 'created_at' => $this->ahora, 'updated_at' => $this->ahora,
                ];
                if (count($detalles) >= 1000) {
                    $this->insertar('cf_encuestas_respuestas_detalle', $detalles);
                    $detalles = [];
                }
            }
        }
        $this->insertar('cf_encuestas_respuestas_detalle', $detalles);

        $this->totales['respuestas'] = count($filas);
        $this->totales['por_clasificacion'] = $clasif;
        $this->totales['por_version'] = $porVersion;
        $this->totales['detalles'] = $totalDetalles;
        $this->totales['detalles_por_clave'] = $porClave;
        $this->totales['vacias_por_clave'] = $vaciasPorClave;
        $this->totales['solo_espacios_por_clave'] = $soloEspacios;   // contestadas con solo espacios/saltos: se conservan exactas
        $this->totales['detalles_mapeados_a_pregunta'] = $mapeadas;
        $this->totales['detalles_solo_clave_historica'] = $soloClave;
        $this->totales['staging_intacto'] = 'pregunta1..9 y justificacion_pregunta1 se conservan en stg_ev_encuesta';
    }

    // ── Validación contra el staging ──────────────────────────────────────────────────────────────────────

    private function validar(): void
    {
        $stg = fn (string $t) => $this->stg()->table($t);
        $noVacias = [];
        foreach (self::CLAVES_VALOR as $c) {
            // Por LONGITUD, no con <> '': en MySQL la comparación ignora los espacios finales y una respuesta de solo espacios parecería vacía.
            $noVacias[$c] = $stg('stg_ev_encuesta')->whereNotNull($c)->whereRaw("LENGTH({$c}) > 0")->count();
        }
        $verif = function (string $clave, int $esperado, int $obtenido) {
            $this->validaciones[] = ['clave' => $clave, 'esperado' => $esperado, 'obtenido' => $obtenido, 'ok' => $esperado === $obtenido];
        };
        $mia = fn (string $t) => DB::table($t)->where('corrida_id', $this->corridaId);

        $verif('respuestas = encuestas del staging', $stg('stg_ev_encuesta')->count(), $mia('cf_encuestas_respuestas')->count());
        $verif('detalles = respuestas no vacías de pregunta1..9 y justificación', array_sum($noVacias), DB::table('cf_encuestas_respuestas_detalle')->whereIn('respuesta_id', $mia('cf_encuestas_respuestas')->select('id'))->count());
        foreach (self::CLAVES_VALOR as $c) {
            $verif("detalles de {$c}", $noVacias[$c], DB::table('cf_encuestas_respuestas_detalle')->whereIn('respuesta_id', $mia('cf_encuestas_respuestas')->select('id'))->where('clave_historica', $c)->count());
        }
        $verif('huérfanas por evento inexistente', $stg('stg_ev_encuesta')->where('evento_existe', false)->count(), $mia('cf_encuestas_respuestas')->where('clasificacion', 'evento_historico_eliminado')->count());
        $verif('con certificado o ambiguas = participante existente', $stg('stg_ev_encuesta')->where('participante_existe', true)->count(),
            $mia('cf_encuestas_respuestas')->whereIn('clasificacion', ['vinculada', 'participante_ambiguo'])->count());
        $verif('sin evento = evento inexistente', $stg('stg_ev_encuesta')->where('evento_existe', false)->count(), $mia('cf_encuestas_respuestas')->whereNull('evento_id')->count());
        $verif('mapa de respuestas', $stg('stg_ev_encuesta')->count(), DB::table('cf_migraciones_map')->where('corrida_id', $this->corridaId)->where('origen_tabla', 'encuesta')->count());
        $verif('respuestas con valor exacto (sin pérdida)', 0, DB::table('cf_encuestas_respuestas_detalle as d')->join('cf_encuestas_respuestas as r', 'r.id', '=', 'd.respuesta_id')->where('r.corrida_id', $this->corridaId)->whereNull('d.valor_texto')->count());

        $mal = array_filter($this->validaciones, fn ($v) => ! $v['ok']);
        if ($mal !== []) {
            throw new RuntimeException('La migración de encuestas no cuadra con el staging en: '.implode(', ', array_column($mal, 'clave')).'. Se revierte toda la corrida.');
        }
    }

    // ── Utilidades ────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed>|null $detalle */
    private function mapa(string $origenTabla, string $origenId, string $destinoTabla, int $destinoId, string $relacion = 'principal', ?array $detalle = null): void
    {
        $this->bufferMapa[] = [
            'corrida_id' => $this->corridaId, 'origen_tabla' => $origenTabla, 'origen_id' => $origenId, 'destino_tabla' => $destinoTabla, 'destino_id' => $destinoId,
            'relacion' => $relacion, 'detalle' => $detalle === null ? null : json_encode($detalle, JSON_UNESCAPED_UNICODE), 'created_at' => $this->ahora, 'updated_at' => $this->ahora,
        ];
        if (count($this->bufferMapa) >= 500) {
            $this->vaciarMapa();
        }
    }

    private function vaciarMapa(): void
    {
        $this->insertar('cf_migraciones_map', $this->bufferMapa);
        $this->bufferMapa = [];
    }

    /** @param array<int,array<string,mixed>> $filas */
    private function insertar(string $tabla, array $filas): void
    {
        if ($filas === []) {
            return;
        }
        $columnas = count($filas[0]);
        $tam = DB::connection()->getDriverName() === 'sqlite' ? max(1, intdiv(900, $columnas)) : max(1, min(1000, intdiv(60000, $columnas)));
        foreach (array_chunk($filas, $tam) as $trozo) {
            DB::table($tabla)->insert($trozo);
        }
    }
}
