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
 * Migración DELTA de encuestas (Fase 11F-B): respuestas posteriores al snapshot ya migrado. REUTILIZA la encuesta, versiones,
 * preguntas y opciones de la corrida base (nunca las modifica ni las duplica) y solo crea respuestas y detalles nuevos.
 *
 *  - Tipo de corrida propio (`encuestas_delta`), mapa propio (origen `encuesta`) y rollback propio.
 *  - Requiere la corrida base de encuestas y que los eventos/certificados a los que se ligan ya estén migrados (base o delta de
 *    certificados): una respuesta de un evento existente en el origen pero no migrado se RECHAZA (no se inventa ni se descarta).
 *  - Una respuesta que necesitaría una versión u opción que la estructura base no tiene se RECHAZA: el delta no cambia estructura.
 *  - Mismas reglas de clasificación y de conservación exacta de valores que MigradorEncuestas.
 */
final class MigradorDeltaEncuestas
{
    private const AUSENTE = 'ausente_en_origen';

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

    public function __construct(
        private readonly ?string $conexionStaging = null,
        private readonly ?string $confirmoProduccion = null,
        private readonly string $comando = '',
    ) {}

    private function stg(): ConnectionInterface
    {
        return DB::connection($this->conexionStaging);
    }

    public function ejecutar(bool $simular = false): ResultadoMigracion
    {
        GuardiaMigracion::exigirDestinoSeguro(null, $this->confirmoProduccion, $this->comando);
        GuardiaMigracion::exigirOrigenStaging($this->conexionStaging, $this->confirmoProduccion, $this->comando);

        $inicio = microtime(true);
        $baseEnc = MigracionCorrida::where('tipo', MigracionCorrida::TIPO_ENCUESTAS)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->orderByDesc('id')->first()
            ?? throw new DeltaRechazado(DeltaRechazado::SIN_BASE, 'No hay una corrida base de encuestas completada.');
        MigracionCorrida::where('tipo', MigracionCorrida::TIPO_LEGADO)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->exists()
            || throw new DeltaRechazado(DeltaRechazado::SIN_BASE, 'No hay una corrida base de certificados completada.');

        [$snapshotSha, $huellaGlobal, $huellaDerivada, $tomadoAt] = $this->leerHuellas();

        $existente = MigracionCorrida::where('tipo', MigracionCorrida::TIPO_ENCUESTAS_DELTA)->where('huella_global', $huellaGlobal)
            ->where('huella_derivada', $huellaDerivada)->where('snapshot_sha256', $snapshotSha);
        $hecha = (clone $existente)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->orderByDesc('id')->first();
        if ($hecha !== null) {
            return new ResultadoMigracion(true, $hecha->id, $hecha->totales ?? [], "Ese snapshot ya está incorporado (delta de encuestas #{$hecha->id}, completado). No se hizo ningún cambio.");
        }
        if ((clone $existente)->where('estado', MigracionCorrida::ESTADO_EJECUTANDO)->exists()) {
            throw new RuntimeException('Hay otro delta de encuestas de este snapshot en estado «ejecutando». No se inicia otro.');
        }

        $corte = $this->snapshotDeCorte();
        $this->rechazarDiferencias($corte);

        $mapa = $this->mapeadas();
        $nuevas = $this->stg()->table('stg_ev_encuesta')->where('primera_snapshot_id', '>', $corte)->where('estado_fila', '!=', self::AUSENTE)->orderBy('fecha')->orderBy('old_id')->get()
            ->filter(fn ($f) => ! isset($mapa[(string) $f->old_id]))->values()->all();
        $resumen = ['snapshot_corte' => $corte, 'respuestas_nuevas' => count($nuevas)];
        if ($nuevas === []) {
            return new ResultadoMigracion(true, 0, $resumen, 'No hay encuestas nuevas posteriores al último snapshot migrado. No se hizo ningún cambio.');
        }

        $estructura = $this->estructuraBase($baseEnc->id);
        $enlaces = $this->enlaces();
        $this->rechazarEstructuraYEventos($nuevas, $estructura, $enlaces);
        if ($simular) {
            return new ResultadoMigracion(true, 0, $resumen + ['simulacion' => true], 'Simulación: el delta de encuestas se puede aplicar tal como está. No se escribió nada.');
        }

        $corrida = MigracionCorrida::create([
            'tipo' => MigracionCorrida::TIPO_ENCUESTAS_DELTA, 'snapshot_sha256' => $snapshotSha, 'huella_global' => $huellaGlobal, 'huella_derivada' => $huellaDerivada,
            'estado' => MigracionCorrida::ESTADO_EJECUTANDO, 'iniciado_at' => now(),
        ]);
        $this->corridaId = $corrida->id;
        $this->ahora = now()->format('Y-m-d H:i:s');

        try {
            DB::transaction(function () use ($inicio, $nuevas, $estructura, $enlaces, $resumen, $snapshotSha, $huellaGlobal, $huellaDerivada, $tomadoAt, $baseEnc) {
                $t = microtime(true);
                $this->crearRespuestas($nuevas, $estructura, $enlaces);
                $this->vaciarMapa();
                $this->tiempos['respuestas'] = (microtime(true) - $t) * 1000;
                $this->validar($nuevas);
                $this->totales += $resumen;
                $this->totales['huellas'] = ['snapshot_sha256' => $snapshotSha, 'global' => $huellaGlobal, 'derivada' => $huellaDerivada, 'snapshot_tomado_at' => $tomadoAt, 'corrida_encuestas_base' => $baseEnc->id];
                $this->totales['validaciones'] = $this->validaciones;
                $this->totales['tiempos_ms'] = array_map(fn ($x) => (int) round($x), $this->tiempos) + ['total' => (int) round((microtime(true) - $inicio) * 1000)];
                MigracionCorrida::whereKey($this->corridaId)->update(['estado' => MigracionCorrida::ESTADO_COMPLETADA, 'finalizado_at' => now(), 'totales' => json_encode($this->totales, JSON_UNESCAPED_UNICODE)]);
            });
        } catch (Throwable $e) {
            MigracionCorrida::whereKey($this->corridaId)->update([
                'estado' => MigracionCorrida::ESTADO_FALLIDA, 'finalizado_at' => now(), 'error_codigo' => mb_substr($e::class, 0, 120),
                'totales' => json_encode(['validaciones' => $this->validaciones], JSON_UNESCAPED_UNICODE),
            ]);

            throw $e;
        }

        return new ResultadoMigracion(false, $this->corridaId, $this->totales, "Delta de encuestas completado (corrida #{$this->corridaId}).");
    }

    // ── Huellas, corte y diferencias ──────────────────────────────────────────────────────────────────────

    /** @return array{0:string,1:string,2:string,3:?string} */
    private function leerHuellas(): array
    {
        $generar = fn () => (new ReporteConciliacion)->generar();
        $r = $this->conexionStaging === null ? $generar() : DB::usingConnection($this->conexionStaging, $generar);
        if ($r['snapshot'] === null || $r['snapshot']['dump_sha256'] === null) {
            throw new RuntimeException('El staging no tiene ningún snapshot cargado.');
        }

        return [$r['snapshot']['dump_sha256'], $r['huella_contenido'], $r['huella_derivada'], $r['snapshot']['tomado_at'] ?? null];
    }

    private function snapshotDeCorte(): int
    {
        $shas = MigracionCorrida::whereIn('tipo', [MigracionCorrida::TIPO_ENCUESTAS, MigracionCorrida::TIPO_ENCUESTAS_DELTA])
            ->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->pluck('snapshot_sha256')->all();
        $ids = $this->stg()->table('stg_ev_snapshots')->whereIn('dump_sha256', $shas)->pluck('id')->all();
        if ($ids === []) {
            throw new DeltaRechazado(DeltaRechazado::SIN_BASE, 'El staging no contiene el snapshot de la corrida base de encuestas.');
        }

        return (int) max($ids);
    }

    private function rechazarDiferencias(int $corte): void
    {
        $s = $this->stg();
        $cambiadas = [];
        $ausentes = [];
        foreach (['encuesta' => ['stg_ev_encuesta', 'old_id'], 'preguntas' => ['stg_ev_preguntas', 'old_id'], 'opciones' => ['stg_ev_opciones', 'old_id']] as $entidad => [$tabla, $col]) {
            $claves = $s->table($tabla)->where('primera_snapshot_id', '<=', $corte)->where('estado_fila', 'cambiada')->pluck($col)->map(fn ($v) => (string) $v)->all();
            $hist = $s->table('stg_ev_historial')->where('entidad', $entidad)->where('snapshot_id', '>', $corte)->pluck('old_id')->map(fn ($v) => (string) $v)->all();
            $todas = array_values(array_unique([...$claves, ...$hist]));
            if ($todas !== []) {
                $cambiadas[$entidad] = count($todas);
            }
            $n = $s->table($tabla)->where('primera_snapshot_id', '<=', $corte)->where('estado_fila', self::AUSENTE)->count();
            if ($n > 0) {
                $ausentes[$entidad] = $n;
            }
        }
        if ($cambiadas !== []) {
            throw new DeltaRechazado(DeltaRechazado::FILAS_CAMBIADAS, 'Hay encuestas o catálogo ya migrados que cambiaron en el origen ('.json_encode($cambiadas).'). El delta no actualiza: se detiene.', ['cambiadas' => $cambiadas, 'ausentes' => $ausentes]);
        }
        if ($ausentes !== []) {
            throw new DeltaRechazado(DeltaRechazado::FILAS_AUSENTES, 'Hay encuestas o catálogo ya migrados que ya no existen en el origen ('.json_encode($ausentes).'). El delta no borra: se detiene.', ['ausentes' => $ausentes, 'cambiadas' => $cambiadas]);
        }
    }

    /** @return array<string,string> origen_id → destino_id de las respuestas ya migradas por la base o un delta anterior */
    private function mapeadas(): array
    {
        return DB::table('cf_migraciones_map as m')->join('cf_migraciones_corridas as c', 'c.id', '=', 'm.corrida_id')
            ->whereIn('c.tipo', [MigracionCorrida::TIPO_ENCUESTAS, MigracionCorrida::TIPO_ENCUESTAS_DELTA])->where('c.estado', MigracionCorrida::ESTADO_COMPLETADA)
            ->where('m.origen_tabla', 'encuesta')->pluck('m.destino_id', 'm.origen_id')->all();
    }

    // ── Estructura base y enlaces ─────────────────────────────────────────────────────────────────────────

    /** @return array<string,array{id:int,numero:int,preguntas:array<string,array{id:int,tipo:string,opciones:array<string,int>}>}> por código de versión */
    private function estructuraBase(int $corridaBase): array
    {
        $r = [];
        foreach (DB::table('cf_encuestas_versiones')->where('corrida_id', $corridaBase)->orderBy('numero')->get(['id', 'numero', 'snapshot']) as $v) {
            $codigo = (string) (json_decode((string) $v->snapshot, true)['codigo'] ?? '');
            if ($codigo === '') {
                continue;
            }
            $preguntas = [];
            foreach (DB::table('cf_encuestas_preguntas')->where('version_id', $v->id)->get(['id', 'clave', 'tipo']) as $p) {
                $opciones = DB::table('cf_encuestas_opciones')->where('pregunta_id', $p->id)->pluck('id', 'valor')->all();
                $preguntas[$p->clave] = ['id' => (int) $p->id, 'tipo' => (string) $p->tipo, 'opciones' => array_map('intval', $opciones)];
            }
            $r[$codigo] = ['id' => (int) $v->id, 'numero' => (int) $v->numero, 'preguntas' => $preguntas];
        }

        return $r;
    }

    /** @return array{eventos:array<string,string>,certificados:array<string,array<int,object>>} */
    private function enlaces(): array
    {
        $eventos = DB::table('cf_migraciones_map as m')->join('cf_migraciones_corridas as c', 'c.id', '=', 'm.corrida_id')
            ->whereIn('c.tipo', [MigracionCorrida::TIPO_LEGADO, MigracionCorrida::TIPO_LEGADO_DELTA])->where('c.estado', MigracionCorrida::ESTADO_COMPLETADA)
            ->where('m.origen_tabla', 'evento')->pluck('m.destino_id', 'm.origen_id')->all();

        return ['eventos' => $eventos, 'certificados' => []];
    }

    /** @return array<int,object> candidatos del evento destino con ese documento (cualquier corrida) */
    private function candidatos(int $eventoId, string $clave): array
    {
        return DB::table('cf_certificados_legado')->where('evento_id', $eventoId)->where('documento_clave', $clave)->get(['id', 'conciliacion_estado'])->all();
    }

    private function versionDe(object $f): string
    {
        return $f->fecha >= MigradorEncuestas::CORTE_ACTUAL ? 'actual' : ($f->fecha >= MigradorEncuestas::CORTE_INTERMEDIA ? 'intermedia' : 'inicial');
    }

    /**
     * @param  array<int,object>  $nuevas
     * @param  array<string,mixed>  $estructura
     * @param  array<string,mixed>  $enlaces
     */
    private function rechazarEstructuraYEventos(array $nuevas, array $estructura, array $enlaces): void
    {
        $sinEvento = 0;
        $versionAusente = [];
        $opcionNueva = 0;
        foreach ($nuevas as $f) {
            if ($f->evento_existe && ! isset($enlaces['eventos'][(string) $f->old_evento_id])) {
                $sinEvento++;

                continue;
            }
            $cod = $this->versionDe($f);
            if (! isset($estructura[$cod])) {
                $versionAusente[$cod] = ($versionAusente[$cod] ?? 0) + 1;

                continue;
            }
            foreach ([1, 2, 3, 4] as $i) {
                $v = $f->{"pregunta{$i}"};
                if ($v !== null && $v !== '' && isset($estructura[$cod]['preguntas']["pregunta{$i}"]) && ! isset($estructura[$cod]['preguntas']["pregunta{$i}"]['opciones'][$v])) {
                    $opcionNueva++;
                }
            }
        }
        if ($sinEvento > 0) {
            throw new DeltaRechazado(DeltaRechazado::EVENTO_NO_MIGRADO, "Hay {$sinEvento} encuesta(s) nueva(s) de un evento que existe en el origen pero aún no está migrado: aplica primero el delta de certificados.", ['encuestas' => $sinEvento]);
        }
        if ($versionAusente !== [] || $opcionNueva > 0) {
            throw new DeltaRechazado(
                DeltaRechazado::ESTRUCTURA_ENCUESTA_NUEVA,
                'Hay respuestas nuevas que requieren una versión u opción que la estructura base no tiene. El delta no cambia la estructura: se detiene.',
                ['versiones_ausentes' => $versionAusente, 'valores_de_opcion_nuevos' => $opcionNueva]
            );
        }
    }

    // ── Respuestas ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int,object>  $nuevas
     * @param  array<string,mixed>  $estructura
     * @param  array<string,mixed>  $enlaces
     */
    private function crearRespuestas(array $nuevas, array $estructura, array $enlaces): void
    {
        $clasif = [];
        $porVersion = [];
        $detalles = [];
        $totalDetalles = 0;
        $mapeados = 0;
        $soloClave = 0;

        foreach ($nuevas as $f) {
            $cod = $this->versionDe($f);
            $v = $estructura[$cod];
            $eventoId = $f->evento_existe ? (int) $enlaces['eventos'][(string) $f->old_evento_id] : null;
            $certId = null;
            if (! $f->evento_existe) {
                $clase = 'evento_historico_eliminado';
            } else {
                $cands = $this->candidatos($eventoId, (string) $f->documento_clave);
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
            $this->mapa('encuesta', (string) $f->old_id, 'cf_encuestas_respuestas', $respuestaId, 'principal', ['clasificacion' => $clase, 'version' => $v['numero'], 'delta' => true] + ($f->evento_existe ? [] : ['old_evento_id' => (int) $f->old_evento_id]));

            foreach (self::CLAVES_VALOR as $clave) {
                $valor = $f->{$clave};
                if ($valor === null || $valor === '') {
                    continue;
                }
                $totalDetalles++;
                $p = $v['preguntas'][$clave] ?? null;
                $opId = $p !== null && $p['tipo'] === 'opcion_unica' ? ($p['opciones'][$valor] ?? null) : null;
                $p !== null ? $mapeados++ : $soloClave++;
                $detalles[] = [
                    'respuesta_id' => $respuestaId, 'pregunta_id' => $p['id'] ?? null, 'clave_historica' => $clave, 'valor_texto' => $valor, 'valor_numero' => null, 'opcion_id' => $opId,
                    'snapshot_pregunta' => json_encode($p === null ? ['clave' => $clave, 'tipo' => null, 'texto' => null, 'nota' => 'Sin pregunta configurable en esta versión.'] : ['clave' => $clave, 'tipo' => $p['tipo'], 'texto' => $this->textoPregunta($p['id'])], JSON_UNESCAPED_UNICODE),
                    'snapshot_opcion' => $opId === null ? null : $valor, 'created_at' => $this->ahora, 'updated_at' => $this->ahora,
                ];
                if (count($detalles) >= 500) {
                    $this->insertar('cf_encuestas_respuestas_detalle', $detalles);
                    $detalles = [];
                }
            }
        }
        $this->insertar('cf_encuestas_respuestas_detalle', $detalles);

        $this->totales['respuestas'] = count($nuevas);
        $this->totales['por_clasificacion'] = $clasif;
        $this->totales['por_version'] = $porVersion;
        $this->totales['detalles'] = $totalDetalles;
        $this->totales['detalles_mapeados_a_pregunta'] = $mapeados;
        $this->totales['detalles_solo_clave_historica'] = $soloClave;
    }

    /** @var array<int,?string> */
    private array $textos = [];

    private function textoPregunta(int $id): ?string
    {
        return $this->textos[$id] ??= (string) DB::table('cf_encuestas_preguntas')->where('id', $id)->value('texto');
    }

    // ── Validación ────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<int,object> $nuevas */
    private function validar(array $nuevas): void
    {
        $mia = fn (string $t) => DB::table($t)->where('corrida_id', $this->corridaId);
        $noVacios = 0;
        foreach ($nuevas as $f) {
            foreach (self::CLAVES_VALOR as $c) {
                if ($f->{$c} !== null && $f->{$c} !== '') {
                    $noVacios++;
                }
            }
        }
        $sinEvento = collect($nuevas)->filter(fn ($f) => ! $f->evento_existe)->count();
        $esperado = [
            'respuestas_nuevas' => [count($nuevas), $mia('cf_encuestas_respuestas')->count()],
            'detalles_de_respuestas_no_vacias' => [$noVacios, DB::table('cf_encuestas_respuestas_detalle')->whereIn('respuesta_id', $mia('cf_encuestas_respuestas')->select('id'))->count()],
            'huerfanas_por_evento_inexistente' => [$sinEvento, $mia('cf_encuestas_respuestas')->where('clasificacion', 'evento_historico_eliminado')->count()],
            'mapa_de_respuestas' => [count($nuevas), DB::table('cf_migraciones_map')->where('corrida_id', $this->corridaId)->where('origen_tabla', 'encuesta')->count()],
            'detalles_con_valor_exacto' => [0, DB::table('cf_encuestas_respuestas_detalle as d')->join('cf_encuestas_respuestas as r', 'r.id', '=', 'd.respuesta_id')->where('r.corrida_id', $this->corridaId)->whereNull('d.valor_texto')->count()],
        ];
        $errores = [];
        foreach ($esperado as $clave => [$e, $o]) {
            $ok = (int) $e === (int) $o;
            $this->validaciones[] = ['clave' => $clave, 'esperado' => (int) $e, 'obtenido' => (int) $o, 'ok' => $ok];
            if (! $ok) {
                $errores[] = $clave;
            }
        }
        if ($errores !== []) {
            throw new RuntimeException('El delta de encuestas no cuadra con el staging en: '.implode(', ', $errores).'. Se revierte toda la corrida.');
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
