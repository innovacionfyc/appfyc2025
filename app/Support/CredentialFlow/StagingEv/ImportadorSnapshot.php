<?php

namespace App\Support\CredentialFlow\StagingEv;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cargador IDEMPOTENTE del snapshot del sistema viejo en las tablas stg_ev_*. Nunca toca tablas finales (cf_*).
 *
 * Por cada entidad y cada fila de origen (clave = id viejo, o la ruta relativa en las imágenes):
 *  - no existía               → se inserta (estado_fila = nueva);
 *  - existía con el mismo hash → solo cambia ultima_snapshot_id (estado_fila = igual);
 *  - existía con otro hash    → la versión anterior pasa a stg_ev_historial y la fila se actualiza (cambiada);
 *  - existía y ya no aparece  → NO se borra: estado_fila = ausente_en_origen.
 * La misma clave nunca se duplica. Cargar dos veces el mismo dump (mismo SHA-256) no hace nada.
 */
final class ImportadorSnapshot
{
    /** Orden de carga de las entidades de base de datos. */
    private const ORDEN = ['evento', 'preguntas', 'opciones', 'participante', 'token', 'descargas', 'encuesta'];

    /** @param  array<int,array<string,mixed>>|null  $imagenes  descripciones de EscanerImagenes (null = no tocar el inventario) */
    public function cargar(SnapshotInfo $info, FuenteSnapshot $fuente, ?array $imagenes = null, bool $forzar = false): ResultadoCarga
    {
        if (! $forzar && $info->dumpSha256 !== null) {
            $previo = DB::table('stg_ev_snapshots')->where('dump_sha256', $info->dumpSha256)->value('id');
            if ($previo !== null) {
                return new ResultadoCarga((int) $previo, true);
            }
        }
        if (DB::table('stg_ev_snapshots')->where('etiqueta', $info->etiqueta)->exists()) {
            throw new RuntimeException('Ya existe un snapshot con esa etiqueta.');
        }

        return DB::transaction(function () use ($info, $fuente, $imagenes) {
            $origen = $fuente->conteos();
            $sid = (int) DB::table('stg_ev_snapshots')->insertGetId([
                'etiqueta' => $info->etiqueta,
                'origen' => $info->origen,
                'dump_archivo' => $info->dumpArchivo,
                'dump_sha256' => $info->dumpSha256,
                'tomado_at' => $info->tomadoAt,
                'servidor_origen' => $info->servidorOrigen,
                'esquema_sha256' => $fuente->esquemaSha256(),
                'inventario_imagenes_sha256' => $info->inventarioImagenesSha256,
                'norm_version' => Normalizador::VERSION,
                'conteos' => json_encode(['origen' => $origen], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $stats = [];
            foreach (self::ORDEN as $entidad) {
                $cfg = $this->configuracion($entidad);
                $stats[$entidad] = $this->sincronizar($cfg, $fuente->filas($cfg['origen']), $sid, $origen[$cfg['origen']] ?? 0);
            }
            if ($imagenes !== null) {
                $stats['imagenes'] = $this->sincronizar($this->configuracion('imagenes'), $imagenes, $sid, count($imagenes));
            }

            (new Conciliador)->ejecutar($imagenes !== null, $sid);

            DB::table('stg_ev_snapshots')->where('id', $sid)->update([
                'conteos' => json_encode(['origen' => $origen, 'staging' => $this->conteosStaging(), 'carga' => $stats], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

            return new ResultadoCarga($sid, false, $stats);
        });
    }

    /** @return array<string,int> filas vigentes (no ausentes) por tabla de staging */
    private function conteosStaging(): array
    {
        $r = [];
        foreach (['evento', 'participante', 'token', 'descargas', 'encuesta', 'preguntas', 'opciones', 'imagenes'] as $e) {
            $r[$e] = (int) DB::table("stg_ev_{$e}")->where('estado_fila', '<>', 'ausente_en_origen')->count();
        }

        return $r;
    }

    /**
     * @param  array<string,mixed>  $cfg
     * @param  iterable<int,array<string,mixed>>  $filas
     * @return array{nuevas:int,iguales:int,cambiadas:int,ausentes:int,origen:int}
     */
    private function sincronizar(array $cfg, iterable $filas, int $sid, int $totalOrigen): array
    {
        $tabla = $cfg['tabla'];
        $col = $cfg['clave'];
        $existentes = [];
        foreach (DB::table($tabla)->select([$col, 'hash_fila', 'norm_version'])->get() as $e) {
            $existentes[(string) $e->{$col}] = [$e->hash_fila, (int) $e->norm_version];
        }

        $vistos = [];
        $nuevas = $iguales = $cambiadas = 0;
        $insertar = [];
        $igualesIds = [];
        $ahora = now();

        $volcarInsertar = function () use (&$insertar, $tabla) {
            foreach (array_chunk($insertar, 400) as $trozo) {
                DB::table($tabla)->insert($trozo);
            }
            $insertar = [];
        };
        $volcarIguales = function () use (&$igualesIds, $tabla, $col, $sid, $ahora) {
            foreach (array_chunk($igualesIds, 400) as $trozo) {
                DB::table($tabla)->whereIn($col, $trozo)->update(['ultima_snapshot_id' => $sid, 'estado_fila' => 'igual', 'updated_at' => $ahora]);
            }
            $igualesIds = [];
        };

        foreach ($filas as $src) {
            $clave = (string) $cfg['clave_de']($src);
            if (isset($vistos[$clave])) {
                throw new RuntimeException("La clave {$clave} está repetida en {$tabla}: el origen no es consistente.");
            }
            $vistos[$clave] = true;

            $hash = Normalizador::hashFila($cfg['hash_de']($src));
            $derivado = $cfg['derivar']($src);

            if (! isset($existentes[$clave])) {
                $nuevas++;
                $insertar[] = [$col => $cfg['clave_valor']($src)] + $derivado + $this->control($sid, $hash, 'nueva', $derivado, $cfg, $ahora);
                if (count($insertar) >= 400) {
                    $volcarInsertar();
                }

                continue;
            }

            [$hashPrevio, $normPrevia] = $existentes[$clave];
            if ($hashPrevio === $hash) {
                $iguales++;
                $igualesIds[] = $cfg['clave_valor']($src);
                if ($normPrevia < Normalizador::VERSION) {
                    DB::table($tabla)->where($col, $cfg['clave_valor']($src))->update($derivado + ['norm_version' => Normalizador::VERSION, 'updated_at' => $ahora]);
                }
                if (count($igualesIds) >= 400) {
                    $volcarIguales();
                }

                continue;
            }

            $cambiadas++;
            $previa = DB::table($tabla)->where($col, $cfg['clave_valor']($src))->first();
            $anteriores = [];
            foreach ($cfg['originales'] as $colStaging => $colOrigen) {
                $anteriores[$colOrigen] = $previa->{$colStaging};
            }
            DB::table('stg_ev_historial')->insert([
                'entidad' => $cfg['entidad'],
                'old_id' => $col === 'old_id' ? (int) $clave : null,
                'clave' => $col === 'old_id' ? null : $clave,
                'snapshot_id' => $sid,
                'snapshot_anterior_id' => $previa->ultima_snapshot_id,
                'hash_anterior' => $hashPrevio,
                'hash_nuevo' => $hash,
                'datos_anteriores' => json_encode($anteriores, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
            [$validacion, $motivo] = $cfg['validar']($derivado);
            DB::table($tabla)->where($col, $cfg['clave_valor']($src))->update($derivado + [
                'hash_fila' => $hash,
                'ultima_snapshot_id' => $sid,
                'estado_fila' => 'cambiada',
                'validacion' => $validacion,
                'motivo' => $motivo,
                'norm_version' => Normalizador::VERSION,
                'updated_at' => $ahora,
            ]);
        }
        $volcarInsertar();
        $volcarIguales();

        // Lo que estaba y ya no aparece NO se borra: se marca.
        $ausentes = DB::table($tabla)
            ->where('ultima_snapshot_id', '<>', $sid)
            ->where('estado_fila', '<>', 'ausente_en_origen')
            ->update(['estado_fila' => 'ausente_en_origen', 'updated_at' => $ahora]);

        return ['nuevas' => $nuevas, 'iguales' => $iguales, 'cambiadas' => $cambiadas, 'ausentes' => $ausentes, 'origen' => $totalOrigen];
    }

    /**
     * @param  array<string,mixed>  $derivado
     * @param  array<string,mixed>  $cfg
     * @return array<string,mixed>
     */
    private function control(int $sid, string $hash, string $estado, array $derivado, array $cfg, $ahora): array
    {
        [$validacion, $motivo] = $cfg['validar']($derivado);

        return [
            'primera_snapshot_id' => $sid,
            'ultima_snapshot_id' => $sid,
            'hash_fila' => $hash,
            'estado_fila' => $estado,
            'validacion' => $validacion,
            'motivo' => $motivo,
            'norm_version' => Normalizador::VERSION,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];
    }

    // ── Configuración por entidad ──────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function configuracion(string $entidad): array
    {
        $porId = [
            'clave' => 'old_id',
            'clave_de' => fn (array $s) => (int) $s['id'],
            'clave_valor' => fn (array $s) => (int) $s['id'],
            'hash_de' => fn (array $s) => $s,
        ];
        $ok = fn (array $d) => ['ok', null];

        return match ($entidad) {
            'evento' => $porId + [
                'entidad' => 'evento', 'tabla' => 'stg_ev_evento', 'origen' => 'evento',
                'originales' => ['nombre_original' => 'nombre', 'imagen_original' => 'imagen_certificado'],
                'derivar' => fn (array $s) => $this->derivarEvento($s),
                'validar' => fn (array $d) => ReglasValidacion::evento($d),
            ],
            'preguntas' => $porId + [
                'entidad' => 'preguntas', 'tabla' => 'stg_ev_preguntas', 'origen' => 'preguntas_encuesta',
                'originales' => ['texto' => 'texto', 'tipo_respuesta' => 'tipo_respuesta', 'num_opciones' => 'num_opciones'],
                'derivar' => fn (array $s) => ['texto' => $s['texto'] ?? null, 'tipo_respuesta' => $s['tipo_respuesta'] ?? null, 'num_opciones' => $s['num_opciones'] ?? null],
                'validar' => $ok,
            ],
            'opciones' => $porId + [
                'entidad' => 'opciones', 'tabla' => 'stg_ev_opciones', 'origen' => 'opciones_respuesta',
                'originales' => ['opcion' => 'opcion', 'old_pregunta_id' => 'id_pregunta'],
                'derivar' => fn (array $s) => ['opcion' => $s['opcion'] ?? null, 'old_pregunta_id' => $s['id_pregunta'] ?? null],
                'validar' => $ok,
            ],
            'participante' => $porId + [
                'entidad' => 'participante', 'tabla' => 'stg_ev_participante', 'origen' => 'participante',
                'originales' => [
                    'old_evento_id' => 'id_evento', 'tipo_documento_original' => 'tipo_documento', 'documento_original' => 'documento',
                    'nombre_original' => 'nombre', 'correo_original' => 'correo', 'num_verificacion' => 'num_verificacion',
                ],
                'derivar' => fn (array $s) => $this->derivarParticipante($s),
                'validar' => fn (array $d) => ReglasValidacion::participante($d, null),
            ],
            'token' => $porId + [
                'entidad' => 'token', 'tabla' => 'stg_ev_token', 'origen' => 'token',
                'originales' => ['codigo_sha256' => 'codigo', 'fecha_creacion' => 'fecha_creacion', 'old_participante_id' => 'id_participante'],
                'derivar' => fn (array $s) => [
                    'codigo_sha256' => ($s['codigo'] ?? null) === null ? null : hash('sha256', (string) $s['codigo']),
                    'codigo_longitud' => ($s['codigo'] ?? null) === null ? null : strlen((string) $s['codigo']),
                    'fecha_creacion' => self::fecha($s['fecha_creacion'] ?? null),
                    'old_participante_id' => $s['id_participante'] ?? null,
                ],
                'validar' => $ok,
            ],
            'descargas' => $porId + [
                'entidad' => 'descargas', 'tabla' => 'stg_ev_descargas', 'origen' => 'descargas',
                'originales' => ['fecha' => 'fecha', 'old_evento_id' => 'id_evento', 'old_participante_id' => 'id_participante'],
                'derivar' => fn (array $s) => [
                    'fecha' => self::fecha($s['fecha'] ?? null),
                    'old_evento_id' => $s['id_evento'] ?? null,
                    'old_participante_id' => $s['id_participante'] ?? null,
                ],
                'validar' => $ok,
            ],
            'encuesta' => $porId + [
                'entidad' => 'encuesta', 'tabla' => 'stg_ev_encuesta', 'origen' => 'encuesta',
                'originales' => ['old_evento_id' => 'id_evento', 'documento_original' => 'documento_participante', 'fecha' => 'fecha']
                    + array_combine(
                        array_map(fn ($i) => "pregunta{$i}", range(1, 9)),
                        array_map(fn ($i) => "pregunta{$i}", range(1, 9)),
                    ) + ['justificacion_pregunta1' => 'justificacion_pregunta1'],
                'derivar' => fn (array $s) => $this->derivarEncuesta($s),
                'validar' => $ok,
            ],
            'imagenes' => [
                'entidad' => 'imagenes', 'tabla' => 'stg_ev_imagenes', 'origen' => 'imagenes', 'clave' => 'ruta_relativa',
                'clave_de' => fn (array $s) => $s['ruta_relativa'],
                'clave_valor' => fn (array $s) => $s['ruta_relativa'],
                'hash_de' => fn (array $s) => ['ruta_relativa' => $s['ruta_relativa'], 'bytes' => $s['bytes'], 'sha256' => $s['sha256']],
                'originales' => ['bytes' => 'bytes', 'sha256' => 'sha256'],
                'derivar' => fn (array $s) => [
                    'ruta_relativa' => $s['ruta_relativa'],
                    'nombre_original' => $s['nombre_original'],
                    'extension' => $s['extension'],
                    'sha256' => $s['sha256'],
                    'bytes' => $s['bytes'],
                    'ancho_px' => $s['ancho_px'],
                    'alto_px' => $s['alto_px'],
                    'mime_real' => $s['mime_real'],
                    'renderizable_fpdf' => (bool) $s['renderizable_fpdf'],
                    'motivo_no_renderizable' => $s['motivo_no_renderizable'],
                ],
                'validar' => fn (array $d) => ReglasValidacion::imagen($d),
            ],
        };
    }

    /** @return array<string,mixed> */
    private function derivarEvento(array $s): array
    {
        $nombre = $s['nombre'] ?? null;
        $imagen = $s['imagen_certificado'] ?? null;
        $imagen = $imagen === null || trim((string) $imagen) === '' ? null : (string) $imagen;
        [$anio, $origenAnio] = Normalizador::anioDeducible(['nombre' => $nombre, 'imagen' => $imagen]);

        return [
            'nombre_original' => $nombre,
            'nombre_normalizado' => Normalizador::paraBuscar($nombre),
            'imagen_original' => $imagen,
            'imagen_extension' => $imagen === null ? null : Normalizador::extensionFpdf($imagen),
            'imagen_estado' => null,
            'imagen_ref_id' => null,
            'anio_deducido' => $anio,
            'anio_origen' => $origenAnio,
            'participantes_count' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function derivarParticipante(array $s): array
    {
        $doc = $s['documento'] ?? null;
        $evaluacion = Normalizador::evaluarDocumento($doc);
        $correos = Normalizador::resumenCorreos($s['correo'] ?? null);

        return [
            'old_evento_id' => (int) $s['id_evento'],
            'tipo_documento_original' => $s['tipo_documento'] ?? null,
            'tipo_documento' => Normalizador::tipoDocumento($s['tipo_documento'] ?? null),
            'documento_original' => $doc,
            'documento_clave' => Normalizador::documentoClave($doc),
            'documento_impreso' => Normalizador::documentoImpresoLegado($doc),
            'documento_estado' => $evaluacion['estado'],
            'documento_detalle' => $evaluacion['detalle'],
            'documento_whitespace' => $evaluacion['blancos'],
            'documento_normalizado_ws' => $evaluacion['normalizado_whitespace'],
            'nombre_original' => $s['nombre'] ?? null,
            'nombre_normalizado' => Normalizador::nombre($s['nombre'] ?? null),
            'correo_original' => $s['correo'] ?? null,
            'correo_normalizado' => $correos['correo_normalizado'],
            'correo_estado' => $correos['estado'],
            'correos_candidatos' => $correos['candidatos'],
            'correos_validos' => $correos['validos'],
            'correos_firma' => $correos['firma'],
            'num_verificacion' => ($s['num_verificacion'] ?? null) === null ? null : (int) $s['num_verificacion'],
            'grupo_duplicado_id' => null,
            'tiene_evento' => false,
            'descargas_count' => 0,
            'primera_descarga_at' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function derivarEncuesta(array $s): array
    {
        $fila = [
            'old_evento_id' => $s['id_evento'] ?? null,
            'documento_original' => $s['documento_participante'] ?? null,
            'documento_clave' => Normalizador::documentoClave($s['documento_participante'] ?? null),
            'fecha' => self::fecha($s['fecha'] ?? null),
        ];
        foreach (range(1, 9) as $i) {
            $fila["pregunta{$i}"] = $s["pregunta{$i}"] ?? null;
        }
        $fila['justificacion_pregunta1'] = $s['justificacion_pregunta1'] ?? null;
        $fila['evento_existe'] = false;
        $fila['participante_existe'] = false;

        return $fila;
    }

    /** Fecha de origen → texto válido para el staging, o null (incluye las «fechas cero» de MySQL). */
    private static function fecha(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $texto = (string) $valor;

        return $texto === '' || str_starts_with($texto, '0000-00-00') ? null : $texto;
    }
}
