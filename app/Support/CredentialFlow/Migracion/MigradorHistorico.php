<?php

namespace App\Support\CredentialFlow\Migracion;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Correo;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\MigracionCorrida;
use App\Support\CredentialFlow\Legado\EvidenciaPlantillas;
use App\Support\CredentialFlow\Rehearsal\HuellaClave;
use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use App\Support\CredentialFlow\StagingEv\Normalizador;
use App\Support\CredentialFlow\StagingEv\ReporteConciliacion;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Migración histórica LOCAL (Fase 4): del staging validado (stg_ev_*) a las tablas finales de Credential Flow, por CORRIDA.
 *
 *  - Atómica: toda la corrida va en UNA transacción; si cualquier cifra o fila crítica falla, no queda nada a medias.
 *  - Idempotente: la misma huella global + derivada (+ SHA del dump) no se migra dos veces mientras haya una corrida
 *    COMPLETADA (NO-OP con mensaje claro). Si se revierte, una corrida nueva reproduce lo mismo.
 *  - Trazable: cada fila creada lleva corrida_id y cada fila del sistema viejo queda en cf_migraciones_map.
 *  - Sin corrección automática: nada se descarta, se corrige ni se consolida. Los duplicados se conservan TODOS; los
 *    conflictivos quedan pendientes de conciliación; los documentos en revisión se migran marcados; el año no se inventa.
 *  - Sin PDFs, sin copiar imágenes (solo se registran SHA, medidas y MIME), sin tokens, sin encuestas, sin correos reales.
 *
 * El staging se lee con otra conexión (opcional: sin ella se usa la conexión por defecto, que es lo que hacen las pruebas).
 */
final class MigradorHistorico
{
    /** Cada tabla de staging se lee sin las filas que ya no existían en el origen. */
    private const AUSENTE = 'ausente_en_origen';

    private string $ahora;

    private int $corridaId = 0;

    /** @var array<int,array<string,mixed>> */
    private array $bufferMapa = [];

    /** @var array<string,mixed> */
    private array $totales = [];

    /** @var array<string,float> milisegundos por etapa */
    private array $tiempos = [];

    /** @var array<int,array<string,mixed>> */
    private array $validaciones = [];

    public function __construct(private readonly ?string $conexionStaging = null) {}

    private function stg(): ConnectionInterface
    {
        return DB::connection($this->conexionStaging);
    }

    /** Ejecuta la migración (o NO-OP si esas huellas ya están migradas). */
    public function ejecutar(): ResultadoMigracion
    {
        GuardiaMigracion::exigirDestinoSeguro();
        GuardiaMigracion::exigirOrigenStaging($this->conexionStaging);

        $inicio = microtime(true);
        [$snapshotSha, $huellaGlobal, $huellaDerivada, $tomadoAt] = $this->leerHuellas();

        $existente = MigracionCorrida::where('tipo', MigracionCorrida::TIPO_LEGADO)->where('huella_global', $huellaGlobal)
            ->where('huella_derivada', $huellaDerivada)->where('snapshot_sha256', $snapshotSha);
        $hecha = (clone $existente)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->orderByDesc('id')->first();
        if ($hecha !== null) {
            return new ResultadoMigracion(true, $hecha->id, $hecha->totales ?? [], "Ese snapshot ya está migrado (corrida #{$hecha->id}, completada). No se hizo ningún cambio.");
        }
        if ((clone $existente)->where('estado', MigracionCorrida::ESTADO_EJECUTANDO)->exists()) {
            throw new RuntimeException('Hay otra corrida de este snapshot en estado «ejecutando». No se inicia otra.');
        }
        // Esta fase migra UN snapshot por vez: los contenidos (SHA) y los nombres de plantilla son únicos en las tablas finales, así que
        // una corrida de OTRO snapshot encima de una completada chocaría. Se niega con un mensaje claro; la migración incremental
        // (varios snapshots) es de una fase posterior. Para cambiar de snapshot, primero se revierte la corrida anterior.
        $otra = MigracionCorrida::where('tipo', MigracionCorrida::TIPO_LEGADO)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->orderByDesc('id')->first();
        if ($otra !== null) {
            throw new RuntimeException("Ya hay una corrida completada de OTRO snapshot (#{$otra->id}). Esta fase migra un solo snapshot: revierte esa corrida (rollback técnico) antes de migrar otro.");
        }

        $corrida = MigracionCorrida::create([
            'tipo' => MigracionCorrida::TIPO_LEGADO, 'snapshot_sha256' => $snapshotSha, 'huella_global' => $huellaGlobal, 'huella_derivada' => $huellaDerivada,
            'estado' => MigracionCorrida::ESTADO_EJECUTANDO, 'iniciado_at' => now(),
        ]);
        $this->corridaId = $corrida->id;
        $this->ahora = now()->format('Y-m-d H:i:s');

        try {
            DB::transaction(function () use ($inicio, $huellaGlobal, $huellaDerivada, $snapshotSha, $tomadoAt) {
                $this->migrar();
                $this->totales['huellas'] = ['snapshot_sha256' => $snapshotSha, 'global' => $huellaGlobal, 'derivada' => $huellaDerivada, 'snapshot_tomado_at' => $tomadoAt];
                $this->totales['validaciones'] = $this->validaciones;
                $this->totales['tiempos_ms'] = array_map(fn ($t) => (int) round($t), $this->tiempos) + ['total' => (int) round((microtime(true) - $inicio) * 1000)];
                MigracionCorrida::whereKey($this->corridaId)->update([
                    'estado' => MigracionCorrida::ESTADO_COMPLETADA, 'finalizado_at' => now(), 'totales' => json_encode($this->totales + ['huella_clave' => HuellaClave::actual(), 'proceso' => 'legado-11a'], JSON_UNESCAPED_UNICODE),
                ]);
            });
        } catch (Throwable $e) {
            // La transacción ya revirtió todo: la corrida queda «fallida» con un código técnico (sin mensaje ni datos).
            MigracionCorrida::whereKey($this->corridaId)->update([
                'estado' => MigracionCorrida::ESTADO_FALLIDA, 'finalizado_at' => now(), 'error_codigo' => mb_substr($e::class, 0, 120),
                'totales' => json_encode(['validaciones' => $this->validaciones, 'tiempos_ms' => array_map(fn ($t) => (int) round($t), $this->tiempos)], JSON_UNESCAPED_UNICODE),
            ]);

            throw $e;
        }

        return new ResultadoMigracion(false, $this->corridaId, $this->totales, "Migración completada (corrida #{$this->corridaId}).");
    }

    // ── Huellas del staging ───────────────────────────────────────────────────────────────────────────────

    /** @return array{0:string,1:string,2:string,3:?string} SHA del dump, huella global, huella derivada y fecha en que se tomó el snapshot */
    private function leerHuellas(): array
    {
        $generar = fn () => (new ReporteConciliacion)->generar();
        $r = $this->conexionStaging === null ? $generar() : DB::usingConnection($this->conexionStaging, $generar);
        if ($r['snapshot'] === null || $r['snapshot']['dump_sha256'] === null) {
            throw new RuntimeException('El staging no tiene ningún snapshot cargado.');
        }

        return [$r['snapshot']['dump_sha256'], $r['huella_contenido'], $r['huella_derivada'], $r['snapshot']['tomado_at'] ?? null];
    }

    // ── Migración (dentro de la transacción) ──────────────────────────────────────────────────────────────

    private function migrar(): void
    {
        $etapa = function (string $nombre, callable $f) {
            $t = microtime(true);
            $f();
            $this->tiempos[$nombre] = (microtime(true) - $t) * 1000;
        };

        $contenidos = [];   // sha256 => id
        $entradas = [];     // id de imagen del staging => [id entrada, estado, renderizable]
        $faltantes = [];    // ruta original faltante => id entrada
        $eventos = [];      // old_evento_id => [id evento, plantilla usable (id|null)]
        $certificados = []; // old_participante_id => id
        $correosOriginal = []; // old_participante_id => [correo_original, candidatos]

        $etapa('contenidos_y_plantillas', function () use (&$contenidos, &$entradas, &$faltantes) {
            $this->plantillas($contenidos, $entradas, $faltantes);
        });
        $etapa('eventos', function () use ($entradas, $faltantes, &$eventos) {
            $this->eventos($entradas, $faltantes, $eventos);
        });
        $etapa('certificados', function () use ($eventos, &$certificados, &$correosOriginal) {
            $this->certificados($eventos, $certificados, $correosOriginal);
        });
        $etapa('correos', function () use ($certificados, $correosOriginal) {
            $this->correos($certificados, $correosOriginal);
        });
        $etapa('descargas', function () use ($certificados) {
            $this->descargas($certificados);
        });
        $this->vaciarMapa();

        $this->pendientesDeOtrasFases();
        $this->validar();
    }

    // ── Contenidos y entradas de plantilla ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,int>  $contenidos
     * @param  array<int,array{0:int,1:string,2:bool}>  $entradas
     * @param  array<string,int>  $faltantes
     */
    private function plantillas(array &$contenidos, array &$entradas, array &$faltantes): void
    {
        $s = $this->stg();
        $eventoPorImagen = $s->table('stg_ev_evento')->where('estado_fila', '!=', self::AUSENTE)->whereNotNull('imagen_ref_id')->get()->keyBy('imagen_ref_id');
        $evidencia = $this->evidenciaCandidatas();
        $porEstado = ['ok' => 0, 'faltante' => 0, 'huerfana' => 0, 'candidata_revision' => 0, 'extension_invalida' => 0];

        foreach ($s->table('stg_ev_imagenes')->where('estado_fila', '!=', self::AUSENTE)->orderBy('id')->get() as $img) {
            // 1) El CONTENIDO, una sola vez por SHA-256 (624 contenidos para 632 archivos). Sin copiar ningún archivo.
            if (! isset($contenidos[$img->sha256])) {
                $contenidos[$img->sha256] = DB::table('cf_plantillas_legado_contenidos')->insertGetId([
                    'sha256' => $img->sha256, 'bytes' => $img->bytes, 'mime_real' => $img->mime_real, 'ancho_px' => $img->ancho_px, 'alto_px' => $img->alto_px,
                    'ruta_almacenada' => null, 'corrida_id' => $this->corridaId, 'created_at' => $this->ahora, 'updated_at' => $this->ahora,
                ]);
                $this->mapa('imagen_contenido', $img->sha256, 'cf_plantillas_legado_contenidos', $contenidos[$img->sha256]);
            }

            // 2) La ENTRADA por nombre original exacto.
            $evento = $eventoPorImagen[$img->id] ?? null;
            [$estado, $notas] = $this->estadoDeEntrada($img, $evento);
            $porEstado[$estado]++;
            $id = DB::table('cf_plantillas_legado')->insertGetId([
                'contenido_id' => $contenidos[$img->sha256], 'ruta_original' => $img->ruta_relativa, 'nombre_original' => $img->nombre_original,
                'nombre_normalizado' => Normalizador::claveArchivo((string) $img->nombre_original), 'extension_original' => $img->extension,
                'renderizable' => (bool) $img->renderizable_fpdf, 'motivo_no_renderizable' => $img->motivo_no_renderizable, 'estado' => $estado, 'notas' => $notas,
                'corrida_id' => $this->corridaId, 'created_at' => $this->ahora, 'updated_at' => $this->ahora,
            ]);
            $entradas[$img->id] = [$id, $estado, (bool) $img->renderizable_fpdf];
            $this->mapa('imagen', $img->ruta_relativa, 'cf_plantillas_legado', $id);
        }

        // 3) Nombres SIN archivo: la imagen del evento no existe (faltante) o tiene extensión inválida y tampoco hay archivo. Se registra
        //    el nombre sin contenido (nunca se inventa plantilla). Los eventos con archivo ya tienen su entrada (paso 2).
        $nombres = $s->table('stg_ev_evento')->where('estado_fila', '!=', self::AUSENTE)->whereNull('imagen_ref_id')
            ->whereIn('imagen_estado', ['archivo_faltante', 'extension_invalida'])->whereNotNull('imagen_original')->orderBy('old_id')->get();
        foreach ($nombres as $e) {
            $ruta = EscanerImagenes::PREFIJO_RUTA.$e->imagen_original;
            if (isset($faltantes[$ruta])) {
                continue;
            }
            $estado = $e->imagen_estado === 'archivo_faltante' ? 'faltante' : 'extension_invalida';
            $texto = $estado === 'faltante' ? 'La imagen del evento no existe en el sistema viejo.' : 'La imagen del evento no existe y su nombre no tiene una extensión válida para FPDF.';
            $cand = $evidencia[(int) $e->old_id] ?? null;
            $faltantes[$ruta] = DB::table('cf_plantillas_legado')->insertGetId([
                'contenido_id' => null, 'ruta_original' => $ruta, 'nombre_original' => $e->imagen_original,
                'nombre_normalizado' => Normalizador::claveArchivo((string) $e->imagen_original), 'extension_original' => $e->imagen_extension,
                'renderizable' => false, 'motivo_no_renderizable' => $estado === 'faltante' ? 'ARCHIVO_FALTANTE' : 'EXTENSION_INVALIDA', 'estado' => $estado,
                'notas' => $cand === null ? $texto : $this->json([$texto, 'evidencia_candidata' => $cand]),
                'corrida_id' => $this->corridaId, 'created_at' => $this->ahora, 'updated_at' => $this->ahora,
            ]);
            $porEstado[$estado]++;
            $this->mapa('evento_imagen_faltante', $ruta, 'cf_plantillas_legado', $faltantes[$ruta]);
        }

        $this->totales['contenidos'] = count($contenidos);
        $this->totales['plantillas'] = array_sum($porEstado);
        $this->totales['plantillas_por_estado'] = $porEstado;
        $this->totales['plantillas_no_renderizables_fpdf'] = DB::table('cf_plantillas_legado')->where('corrida_id', $this->corridaId)->where('renderizable', false)->whereNotNull('contenido_id')->count();
    }

    /** @return array{0:string,1:?string} estado de la entrada y notas */
    private function estadoDeEntrada(object $img, ?object $evento): array
    {
        if ($evento !== null) {
            return match ($evento->imagen_estado) {
                'ok' => ['ok', null],
                'extension_invalida' => ['extension_invalida', 'El nombre de la imagen no tiene una extensión válida para FPDF: no es renderizable automáticamente, aunque el contenido existe.'],
                default => throw new RuntimeException('Estado de imagen de evento no previsto: '.$evento->imagen_estado),
            };
        }
        if ($img->candidata_revision) {
            return ['candidata_revision', "Candidata para el evento {$img->candidata_old_evento_id} (coincide su nombre normalizado): NO enlazada, decisión humana pendiente."];
        }

        return ['huerfana', 'Sin evento que la use; se conserva catalogada para recuperación manual.'];
    }

    /** @return array<int,array<string,mixed>> evidencia técnica de las candidatas por old_evento_id (sin decisión) */
    private function evidenciaCandidatas(): array
    {
        $generar = fn () => (new EvidenciaPlantillas)->generar();
        $e = $this->conexionStaging === null ? $generar() : DB::usingConnection($this->conexionStaging, $generar);
        $r = [];
        foreach ($e['candidatas'] as $c) {
            $r[$c['old_evento_id']] = ['candidata_sha256' => $c['sha256'], 'similitud_nombre' => $c['similitud_nombre'], 'mime_real' => $c['mime_real'],
                'ancho_px' => $c['ancho_px'], 'alto_px' => $c['alto_px'], 'bytes' => $c['bytes'], 'decision' => $c['decision']];
        }

        return $r;
    }

    // ── Eventos ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int,array{0:int,1:string,2:bool}>  $entradas
     * @param  array<string,int>  $faltantes
     * @param  array<int,array{0:int,1:?int}>  $eventos
     */
    private function eventos(array $entradas, array $faltantes, array &$eventos): void
    {
        $pendientes = 0;
        $sinAnio = ['ANIO_NO_DEDUCIBLE' => 0, 'ANIO_AMBIGUO' => 0];

        foreach ($this->stg()->table('stg_ev_evento')->where('estado_fila', '!=', self::AUSENTE)->orderBy('old_id')->get() as $e) {
            $plantilla = null;
            $usable = null;
            $marcas = [];
            if ($e->imagen_ref_id !== null) {
                [$plantilla, $estado, $renderizable] = $entradas[$e->imagen_ref_id] ?? throw new RuntimeException('Evento con imagen_ref_id sin entrada de plantilla.');
                $usable = ($estado === 'ok' && $renderizable) ? $plantilla : null;
            } elseif (in_array($e->imagen_estado, ['archivo_faltante', 'extension_invalida'], true) && $e->imagen_original !== null) {
                $plantilla = $faltantes[EscanerImagenes::PREFIJO_RUTA.$e->imagen_original] ?? throw new RuntimeException('Evento con imagen sin archivo y sin entrada de plantilla.');
            }
            if ($usable === null) {
                $pendientes++;
                $marcas[] = 'PENDIENTE_PLANTILLA:'.($e->imagen_estado ?? 'sin_imagen');
            }
            if ($e->anio_deducido === null) {
                $codigo = $e->anio_origen === 'ambiguo' ? 'ANIO_AMBIGUO' : 'ANIO_NO_DEDUCIBLE';
                $sinAnio[$codigo]++;
                $marcas[] = $codigo;   // el año NO se inventa: queda NULL y esta marca
            }

            $id = DB::table('cf_eventos')->insertGetId([
                'nombre' => $e->nombre_original ?? '', 'nombre_normalizado' => $e->nombre_normalizado, 'anio' => $e->anio_deducido,
                'fecha_inicio' => null, 'fecha_fin' => null, 'fecha_texto' => null, 'estado' => EventoCertificacion::ESTADO_CERRADO, 'origen' => EventoCertificacion::ORIGEN_LEGADO,
                'plantilla_legado_id' => $plantilla, 'corrida_id' => $this->corridaId,
                'notas' => $this->json(['imagen_estado_origen' => $e->imagen_estado, 'anio_origen' => $e->anio_origen, 'participantes_origen' => (int) $e->participantes_count, 'marcas' => $marcas]),
                'created_at' => $this->ahora, 'updated_at' => $this->ahora,
            ]);
            $eventos[(int) $e->old_id] = [$id, $usable];
            $this->mapa('evento', (string) $e->old_id, 'cf_eventos', $id);
        }

        $this->totales['eventos'] = count($eventos);
        $this->totales['eventos_pendientes_de_plantilla'] = $pendientes;
        $this->totales['eventos_sin_anio'] = $sinAnio;
    }

    // ── Certificados históricos ───────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int,array{0:int,1:?int}>  $eventos
     * @param  array<int,int>  $certificados
     * @param  array<int,array{0:?string,1:int}>  $correosOriginal
     */
    private function certificados(array $eventos, array &$certificados, array &$correosOriginal): void
    {
        $s = $this->stg();
        $grupos = [];
        foreach ($s->table('stg_ev_duplicados')->get() as $g) {
            $ids = json_decode((string) $g->old_ids, true) ?: [];
            $grupos[(int) $g->id] = ['clasificacion' => $g->clasificacion, 'etiquetas' => $g->etiquetas, 'subtipo' => $g->subtipo, 'old_ids' => array_map('intval', $ids), 'canonico' => $ids === [] ? null : (int) min($ids)];
        }

        $c = ['por_conciliacion' => [], 'grupos_vistos' => [], 'filas_identicas' => 0, 'filas_conflictivas' => 0, 'tipo_vacio' => 0, 'con_codigo' => 0, 'sin_plantilla_usable' => 0];
        $codigos = [];

        $s->table('stg_ev_participante')->where('estado_fila', '!=', self::AUSENTE)->orderBy('id')->chunkById(2000, function ($filas) use ($eventos, $grupos, &$certificados, &$correosOriginal, &$c, &$codigos) {
            foreach ($filas as $p) {
                [$eventoId, $usable] = $eventos[(int) $p->old_evento_id] ?? throw new RuntimeException('Participante en un evento que no existe (no se inventa).');
                $g = $p->grupo_duplicado_id === null ? null : ($grupos[(int) $p->grupo_duplicado_id] ?? throw new RuntimeException('Participante en un grupo de duplicados que no existe.'));

                $revision = $p->documento_estado !== 'valido';
                $conflictivo = $g !== null && $g['clasificacion'] === 'conflictivo';
                $identicoNoCanonico = $g !== null && $g['clasificacion'] === 'identico' && (int) $p->old_id !== $g['canonico'];
                // Prioridad (todos los motivos quedan igualmente en el snapshot): revisión del documento > conflictivo >
                // sin plantilla usable > duplicado idéntico no canónico > ok.
                $conciliacion = match (true) {
                    $revision => CertificadoLegado::CONCILIACION_REVISION_DOCUMENTO,
                    $conflictivo => CertificadoLegado::CONCILIACION_PENDIENTE,
                    $usable === null => CertificadoLegado::CONCILIACION_PENDIENTE_PLANTILLA,
                    $identicoNoCanonico => CertificadoLegado::CONCILIACION_DUPLICADO_CONSOLIDADO,
                    default => CertificadoLegado::CONCILIACION_OK,
                };
                $c['por_conciliacion'][$conciliacion] = ($c['por_conciliacion'][$conciliacion] ?? 0) + 1;
                if ($usable === null) {
                    $c['sin_plantilla_usable']++;
                }
                if ($g !== null) {
                    $c['grupos_vistos'][(int) $p->grupo_duplicado_id] = $g['clasificacion'];
                    $c[$conflictivo ? 'filas_conflictivas' : 'filas_identicas']++;
                }

                $tipoVacio = trim((string) $p->tipo_documento_original) === '';
                $c['tipo_vacio'] += $tipoVacio ? 1 : 0;
                $codigo = $p->num_verificacion === null ? null : (string) $p->num_verificacion;
                if ($codigo !== null) {
                    $c['con_codigo']++;
                    $codigos[$codigo] = true;
                }

                $snapshot = [
                    'migracion' => ['origen' => 'evaluaciones', 'old_id' => (int) $p->old_id, 'old_evento_id' => (int) $p->old_evento_id, 'norm_version' => (int) $p->norm_version, 'hash_fila' => $p->hash_fila],
                    'nombre' => $p->nombre_original, 'tipo_documento' => $p->tipo_documento_original, 'documento' => $p->documento_original,
                    'documento_impreso' => $p->documento_impreso, 'documento_estado' => $p->documento_estado, 'documento_detalle' => $p->documento_detalle,
                    'documento_whitespace' => $p->documento_whitespace, 'correo_original' => $p->correo_original, 'correo_estado' => $p->correo_estado,
                    'correos_candidatos' => (int) $p->correos_candidatos, 'correos_validos' => (int) $p->correos_validos,
                    'codigo_legado' => $codigo, 'descargas' => (int) $p->descargas_count, 'primera_descarga_at' => $p->primera_descarga_at,
                    'motivos' => $p->motivo === null ? [] : explode(',', (string) $p->motivo),
                    'advertencias' => $tipoVacio ? ['TIPO_DOCUMENTO_VACIO'] : [],
                    'duplicado' => $g === null ? null : ['grupo_staging' => (int) $p->grupo_duplicado_id, 'clasificacion' => $g['clasificacion'], 'etiquetas' => $g['etiquetas'], 'subtipo' => $g['subtipo'], 'old_ids' => $g['old_ids'], 'canonico_old_id' => $g['canonico']],
                ];

                $id = DB::table('cf_certificados_legado')->insertGetId([
                    'evento_id' => $eventoId, 'plantilla_legado_id' => $usable,
                    'tipo_documento' => $p->tipo_documento_original, 'documento' => $p->documento_original, 'documento_clave' => $p->documento_clave,
                    'nombre_completo' => $p->nombre_original ?? '',
                    'correo' => $p->correo_original, 'correo_normalizado' => $p->correo_normalizado, 'correo_estado' => $p->correo_estado,
                    'codigo_legado' => $codigo, 'snapshot_legado' => $this->json($snapshot),
                    'estado' => CertificadoLegado::ESTADO_VIGENTE, 'conciliacion_estado' => $conciliacion,
                    'grupo_duplicado' => $g === null ? null : 'dup-'.$p->grupo_duplicado_id, 'visible_portal' => false, 'intentos_generacion' => 0,
                    'corrida_id' => $this->corridaId, 'created_at' => $this->ahora, 'updated_at' => $this->ahora,
                ]);
                $certificados[(int) $p->old_id] = $id;
                if ((int) $p->correos_candidatos > 0) {
                    $correosOriginal[(int) $p->old_id] = [$p->correo_original, (int) $p->correos_candidatos];
                }

                $relacion = $conflictivo ? 'variante_conflictiva' : ($g === null ? 'principal' : ((int) $p->old_id === $g['canonico'] ? 'canonico' : 'duplicado_identico'));
                $this->mapa('participante', (string) $p->old_id, 'cf_certificados_legado', $id, $relacion, $g === null ? null : ['grupo_staging' => (int) $p->grupo_duplicado_id, 'canonico_old_id' => $g['canonico']]);
            }
        });

        $clases = array_count_values($c['grupos_vistos']);
        $this->totales['certificados'] = count($certificados);
        $this->totales['certificados_por_conciliacion'] = $c['por_conciliacion'];
        $this->totales['certificados_sin_plantilla_usable'] = $c['sin_plantilla_usable'];
        $this->totales['duplicados'] = [
            'grupos_identicos' => $clases['identico'] ?? 0, 'grupos_conflictivos' => $clases['conflictivo'] ?? 0,
            'filas_identicas' => $c['filas_identicas'], 'filas_conflictivas' => $c['filas_conflictivas'],
            'consolidacion' => 'ninguna: se conservan TODAS las filas y todos los old_id en cf_migraciones_map',
        ];
        $this->totales['codigos_legado'] = ['filas_con_codigo' => $c['con_codigo'], 'codigos_distintos' => count($codigos), 'unique_global' => false];
        $this->totales['advertencias'] = ['TIPO_DOCUMENTO_VACIO' => $c['tipo_vacio']];
    }

    // ── Correos ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int,int>  $certificados
     * @param  array<int,array{0:?string,1:int}>  $correosOriginal
     */
    private function correos(array $certificados, array $correosOriginal): void
    {
        $n = ['total' => 0, 'valido' => 0, 'invalido' => 0, 'normalizado_distinto' => 0];

        $this->stg()->table('stg_ev_participante_correos')->orderBy('id')->chunkById(2000, function ($filas) use ($certificados, $correosOriginal, &$n) {
            $lote = [];
            foreach ($filas as $f) {
                $cert = $certificados[(int) $f->participante_old_id] ?? throw new RuntimeException('Candidato de correo sin certificado.');
                [$original, $candidatos] = $correosOriginal[(int) $f->participante_old_id] ?? [null, 0];
                // Un solo candidato: se conserva la dirección ORIGINAL tal como llegó; con varios, la del candidato (ya recortada).
                $correo = ($candidatos === 1 && $original !== null && trim($original) !== '') ? $original : $f->correo_normalizado;
                if (Correo::normalizar($correo) !== $f->correo_normalizado) {
                    $n['normalizado_distinto']++;
                }
                $n['total']++;
                $n[$f->estado]++;
                $lote[] = [
                    'certificado_legado_id' => $cert, 'participante_id' => null, 'correo' => $correo, 'correo_normalizado' => $f->correo_normalizado,
                    'estado' => $f->estado, 'orden' => $f->orden, 'es_principal' => false, 'origen' => Correo::ORIGEN_LEGADO,
                    'corrida_id' => $this->corridaId, 'created_at' => $this->ahora, 'updated_at' => $this->ahora,
                ];
            }
            $this->insertar('cf_correos', $lote);
        });

        $this->totales['correos'] = $n;
    }

    // ── Descargas ─────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<int,int> $certificados */
    private function descargas(array $certificados): void
    {
        $n = ['migradas' => 0, 'sin_certificado' => 0];
        $ids = [];

        $this->stg()->table('stg_ev_descargas')->where('estado_fila', '!=', self::AUSENTE)->orderBy('id')->chunkById(2000, function ($filas) use ($certificados, &$n, &$ids) {
            foreach ($filas as $d) {
                $cert = $d->old_participante_id === null ? null : ($certificados[(int) $d->old_participante_id] ?? null);
                if ($cert === null) {
                    // No se inventa nada: sin certificado no hay dónde colgar la descarga (el modelo exige un origen). Queda constancia.
                    $n['sin_certificado']++;
                    if (count($ids) < 50) {
                        $ids[] = (int) $d->old_id;
                    }

                    continue;
                }
                $id = DB::table('cf_descargas')->insertGetId([
                    'emision_id' => null, 'certificado_legado_id' => $cert, 'participante_id' => null, 'via' => 'portal', 'descargado_at' => $d->fecha,
                    'ip_hash' => null, 'corrida_id' => $this->corridaId, 'created_at' => $this->ahora, 'updated_at' => $this->ahora,
                ]);
                $n['migradas']++;
                $this->mapa('descarga', (string) $d->old_id, 'cf_descargas', $id);
            }
        });

        $this->totales['descargas'] = $n + ['old_ids_sin_certificado' => $ids];
    }

    // ── Lo que NO se migra todavía ────────────────────────────────────────────────────────────────────────

    private function pendientesDeOtrasFases(): void
    {
        $s = $this->stg();
        $fila = fn (string $t) => $s->table($t)->where('estado_fila', '!=', self::AUSENTE);

        $this->totales['tokens'] = ['en_staging' => $fila('stg_ev_token')->count(), 'migrados' => 0, 'destino' => 'NO_MIGRAR (los reemplaza el OTP; quedan solo en staging/auditoría)'];
        $this->totales['encuestas'] = [
            'en_staging' => $fila('stg_ev_encuesta')->count(), 'preguntas_catalogo' => $fila('stg_ev_preguntas')->count(), 'opciones_catalogo' => $fila('stg_ev_opciones')->count(),
            'migradas' => 0, 'estado' => 'pendiente de una fase posterior (pregunta1..9 se conservan en staging)',
        ];
    }

    // ── Validación contra el staging ──────────────────────────────────────────────────────────────────────

    private function validar(): void
    {
        $s = $this->stg();
        $fila = fn (string $t) => $s->table($t)->where('estado_fila', '!=', self::AUSENTE);
        $mio = fn (string $t) => DB::table($t)->where('corrida_id', $this->corridaId);

        $faltantesDistintos = $fila('stg_ev_evento')->where('imagen_estado', 'archivo_faltante')->distinct()->count('imagen_original');
        $noRenderizables = $fila('stg_ev_evento')->whereIn('imagen_estado', ['archivo_faltante', 'extension_invalida', 'sin_imagen'])->pluck('old_id')->all();
        $grupos = $s->table('stg_ev_duplicados')->selectRaw('clasificacion, COUNT(*) n, SUM(filas) filas')->groupBy('clasificacion')->get()->keyBy('clasificacion');
        $correos = $s->table('stg_ev_participante_correos')->selectRaw('estado, COUNT(*) n')->groupBy('estado')->pluck('n', 'estado');

        $cert = $mio('cf_certificados_legado');
        $esperado = [
            'eventos' => [$fila('stg_ev_evento')->count(), $mio('cf_eventos')->count()],
            'contenidos_distintos' => [$fila('stg_ev_imagenes')->distinct()->count('sha256'), $mio('cf_plantillas_legado_contenidos')->count()],
            'plantillas_con_contenido' => [$fila('stg_ev_imagenes')->count(), $mio('cf_plantillas_legado')->whereNotNull('contenido_id')->count()],
            'plantillas_faltantes' => [$faltantesDistintos, $mio('cf_plantillas_legado')->where('estado', 'faltante')->count()],
            'plantillas_extension_invalida' => [$fila('stg_ev_evento')->where('imagen_estado', 'extension_invalida')->count(), $mio('cf_plantillas_legado')->where('estado', 'extension_invalida')->count()],
            'plantillas_huerfanas' => [$fila('stg_ev_imagenes')->where('huerfana', true)->where('candidata_revision', false)->count(), $mio('cf_plantillas_legado')->where('estado', 'huerfana')->count()],
            'plantillas_candidatas_revision' => [$fila('stg_ev_imagenes')->where('candidata_revision', true)->count(), $mio('cf_plantillas_legado')->where('estado', 'candidata_revision')->count()],
            'certificados' => [$fila('stg_ev_participante')->count(), (clone $cert)->count()],
            'certificados_revision_documento' => [$fila('stg_ev_participante')->where('documento_estado', '!=', 'valido')->count(), (clone $cert)->where('conciliacion_estado', 'revision_documento')->count()],
            'certificados_visible_portal' => [0, (clone $cert)->where('visible_portal', true)->count()],
            'certificados_con_pdf' => [0, (clone $cert)->where(fn ($q) => $q->whereNotNull('pdf_archivo')->orWhereNotNull('materializado_at'))->count()],
            'participantes_en_eventos_sin_plantilla_usable' => [
                $fila('stg_ev_participante')->whereIn('old_evento_id', $noRenderizables)->count(),
                (clone $cert)->whereNull('plantilla_legado_id')->count(),
            ],
            'correos_candidatos' => [(int) $correos->sum(), $mio('cf_correos')->count()],
            'correos_validos' => [(int) ($correos['valido'] ?? 0), $mio('cf_correos')->where('estado', 'valido')->count()],
            'correos_invalidos' => [(int) ($correos['invalido'] ?? 0), $mio('cf_correos')->where('estado', 'invalido')->count()],
            // Una descarga cuyo participante NO existe en el origen no tiene certificado donde colgar (no se inventa): queda en staging.
            'descargas' => [$fila('stg_ev_descargas')->where('participante_existe', true)->count(), $mio('cf_descargas')->count()],
            'descargas_sin_participante_quedan_en_staging' => [$fila('stg_ev_descargas')->where('participante_existe', false)->count(), $this->totales['descargas']['sin_certificado']],
            'grupos_duplicados_identicos' => [(int) ($grupos['identico']->n ?? 0), $this->totales['duplicados']['grupos_identicos']],
            'grupos_duplicados_conflictivos' => [(int) ($grupos['conflictivo']->n ?? 0), $this->totales['duplicados']['grupos_conflictivos']],
            // Todas las variantes de un grupo conflictivo se conservan (una fila por old_id) y ninguna es visible.
            'filas_conflictivas_conservadas' => [
                (int) ($grupos['conflictivo']->filas ?? 0),
                DB::table('cf_migraciones_map')->where('corrida_id', $this->corridaId)->where('relacion', 'variante_conflictiva')->count(),
            ],
            'filas_en_grupos_identicos_conservadas' => [
                (int) ($grupos['identico']->filas ?? 0),
                DB::table('cf_migraciones_map')->where('corrida_id', $this->corridaId)->whereIn('relacion', ['canonico', 'duplicado_identico'])->count(),
            ],
            'filas_con_codigo' => [$fila('stg_ev_participante')->whereNotNull('num_verificacion')->count(), (clone $cert)->whereNotNull('codigo_legado')->count()],
            'codigos_distintos' => [$fila('stg_ev_participante')->whereNotNull('num_verificacion')->distinct()->count('num_verificacion'), (clone $cert)->whereNotNull('codigo_legado')->distinct()->count('codigo_legado')],
            'mapa_participantes' => [$fila('stg_ev_participante')->count(), DB::table('cf_migraciones_map')->where('corrida_id', $this->corridaId)->where('origen_tabla', 'participante')->count()],
            'mapa_eventos' => [$fila('stg_ev_evento')->count(), DB::table('cf_migraciones_map')->where('corrida_id', $this->corridaId)->where('origen_tabla', 'evento')->count()],
        ];

        // Códigos compartidos entre pares (evento, documento) DISTINTOS: no debe haber (un código repetido en un duplicado es normal).
        $esperado['codigos_compartidos_entre_pares_distintos'] = [0, $this->codigosCompartidos()];

        $errores = [];
        foreach ($esperado as $clave => [$e, $o]) {
            $ok = (int) $e === (int) $o;
            $this->validaciones[] = ['clave' => $clave, 'esperado' => (int) $e, 'obtenido' => (int) $o, 'ok' => $ok];
            if (! $ok) {
                $errores[] = $clave;
            }
        }
        if ($errores !== []) {
            throw new RuntimeException('La migración no cuadra con el staging en: '.implode(', ', $errores).'. Se revierte toda la corrida.');
        }
    }

    /** Códigos legado compartidos por (evento, documento) distintos: se calcula en PHP (portable entre motores). */
    private function codigosCompartidos(): int
    {
        $pares = [];
        foreach (DB::table('cf_certificados_legado')->where('corrida_id', $this->corridaId)->whereNotNull('codigo_legado')->select('codigo_legado', 'evento_id', 'documento_clave')->cursor() as $r) {
            $pares[$r->codigo_legado][$r->evento_id."\0".$r->documento_clave] = true;
        }

        return count(array_filter($pares, fn ($p) => count($p) > 1));
    }

    // ── Utilidades ────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed>|null $detalle */
    private function mapa(string $origenTabla, string $origenId, string $destinoTabla, int $destinoId, string $relacion = 'principal', ?array $detalle = null): void
    {
        $this->bufferMapa[] = [
            'corrida_id' => $this->corridaId, 'origen_tabla' => $origenTabla, 'origen_id' => $origenId, 'destino_tabla' => $destinoTabla, 'destino_id' => $destinoId,
            'relacion' => $relacion, 'detalle' => $detalle === null ? null : $this->json($detalle), 'created_at' => $this->ahora, 'updated_at' => $this->ahora,
        ];
        if (count($this->bufferMapa) >= 500) {
            $this->vaciarMapa();
        }
    }

    private function vaciarMapa(): void
    {
        if ($this->bufferMapa !== []) {
            $this->insertar('cf_migraciones_map', $this->bufferMapa);
            $this->bufferMapa = [];
        }
    }

    /** @param array<int,array<string,mixed>> $filas */
    private function insertar(string $tabla, array $filas): void
    {
        if ($filas === []) {
            return;
        }
        $columnas = count($filas[0]);
        // SQLite limita las variables por sentencia (999); MySQL admite muchas más.
        $tam = DB::connection()->getDriverName() === 'sqlite' ? max(1, intdiv(900, $columnas)) : max(1, min(1000, intdiv(60000, $columnas)));
        foreach (array_chunk($filas, $tam) as $trozo) {
            DB::table($tabla)->insert($trozo);
        }
    }

    /** @param array<mixed> $datos */
    private function json(array $datos): string
    {
        return (string) json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
