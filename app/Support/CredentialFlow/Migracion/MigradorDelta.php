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
 * Migración DELTA de certificados (Fase 11F-B): incorpora SOLO lo que apareció en el staging DESPUÉS del snapshot ya migrado
 * (corrida base `legado_evaluaciones` o un delta anterior), sin tocar nada de lo que ya existe.
 *
 *  - Separada del migrador base: tipo de corrida propio (`legado_delta`), comando propio y rollback propio.
 *  - Solo filas NUEVAS: `primera_snapshot_id` posterior al último snapshot migrado y sin entrada en `cf_migraciones_map`. Lo que
 *    ya está en el mapa nunca se vuelve a crear (idempotencia por mapa, no por la etiqueta `estado_fila`).
 *  - Rechaza (antes de escribir) toda diferencia destructiva: filas CAMBIADAS o AUSENTES del origen, y participantes nuevos que
 *    caen en un grupo de duplicados ya existente (eso exigiría reclasificar certificados migrados: ruta controlada aparte).
 *  - Aditivo: eventos, plantillas/contenidos (reutiliza por SHA-256), certificados, correos y descargas. Una descarga nueva sobre
 *    un certificado ya migrado resuelve su destino por `cf_migraciones_map` y solo inserta la descarga.
 *  - No migra tokens, no asigna códigos modernos (preserva el código legado) y no resuelve casos.
 *  - ÚNICA excepción a «base inmutable» (Fase 11F-C2): un participante ya migrado cuyo ÚNICO cambio es `num_verificacion` NULL/vacío →
 *    código (el sistema viejo lo asigna en la primera descarga). Solo si TODAS las invariantes de `evaluarCambioDeCodigo` se cumplen;
 *    actualiza únicamente `codigo_legado` del certificado (acotado por id y por `codigo_legado IS NULL`), deja huella en
 *    `cf_migraciones_map` (origen `participante_codigo`) y es reversible con el rollback del delta. Cualquier otra diferencia sigue
 *    siendo FILAS_CAMBIADAS.
 *  - Atómica (una transacción) y validada contra el subconjunto delta del staging; si algo no cuadra, no queda nada.
 *
 * Reglas de negocio idénticas a MigradorHistorico (conciliación, correos, plantilla usable); la paridad se prueba en
 * MigracionDeltaTest.
 */
final class MigradorDelta
{
    private const AUSENTE = 'ausente_en_origen';

    /** Origen del mapa para la actualización de código de un certificado base (nunca se confunde con `participante`). */
    public const MAPA_CODIGO = 'participante_codigo';

    /** Columna del staging → clave del origen: TODO lo que el staging guarda del participante salvo `num_verificacion`. */
    private const ORIGINALES_SIN_CODIGO = [
        'old_evento_id' => 'id_evento', 'tipo_documento_original' => 'tipo_documento', 'documento_original' => 'documento',
        'nombre_original' => 'nombre', 'correo_original' => 'correo',
    ];

    /** Entidad del historial del staging → (tabla, columna clave) de las tablas que el delta migra y por tanto vigila. */
    private const VIGILADAS = [
        'evento' => ['stg_ev_evento', 'old_id'],
        'participante' => ['stg_ev_participante', 'old_id'],
        'descargas' => ['stg_ev_descargas', 'old_id'],
        'imagenes' => ['stg_ev_imagenes', 'ruta_relativa'],
    ];

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

    /** @var array<int,array{cert_id:int,codigo:string,evento_id:int}> old_id del participante → actualización de código aceptada */
    private array $codigosActualizar = [];

    /** @var array<string,int> motivo → cantidad de cambios de `num_verificacion` rechazados */
    private array $codigosRechazados = [];

    public function __construct(
        private readonly ?string $conexionStaging = null,
        private readonly ?string $confirmoProduccion = null,
        private readonly string $comando = '',
    ) {}

    private function stg(): ConnectionInterface
    {
        return DB::connection($this->conexionStaging);
    }

    /** Con `$simular` calcula y valida el plan SIN escribir nada (las mismas negaciones que la ejecución real). */
    public function ejecutar(bool $simular = false): ResultadoMigracion
    {
        GuardiaMigracion::exigirDestinoSeguro(null, $this->confirmoProduccion, $this->comando);
        GuardiaMigracion::exigirOrigenStaging($this->conexionStaging, $this->confirmoProduccion, $this->comando);

        $inicio = microtime(true);
        $base = MigracionCorrida::where('tipo', MigracionCorrida::TIPO_LEGADO)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->orderByDesc('id')->first()
            ?? throw new DeltaRechazado(DeltaRechazado::SIN_BASE, 'No hay una corrida base de certificados completada: el delta solo incorpora lo posterior a ella.');
        $claveBase = $base->totales['huella_clave'] ?? null;
        if ($claveBase !== null && ! hash_equals((string) $claveBase, HuellaClave::actual())) {
            throw new DeltaRechazado(DeltaRechazado::CLAVE_DISTINTA, 'La APP_KEY actual no es la de la corrida base: los hashes derivados no serían compatibles.');
        }

        [$snapshotSha, $huellaGlobal, $huellaDerivada, $tomadoAt] = $this->leerHuellas();

        $existente = MigracionCorrida::where('tipo', MigracionCorrida::TIPO_LEGADO_DELTA)->where('huella_global', $huellaGlobal)
            ->where('huella_derivada', $huellaDerivada)->where('snapshot_sha256', $snapshotSha);
        $hecha = (clone $existente)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->orderByDesc('id')->first();
        if ($hecha !== null) {
            return new ResultadoMigracion(true, $hecha->id, $hecha->totales ?? [], "Ese snapshot ya está incorporado (delta #{$hecha->id}, completado). No se hizo ningún cambio.");
        }
        if ((clone $existente)->where('estado', MigracionCorrida::ESTADO_EJECUTANDO)->exists()) {
            throw new RuntimeException('Hay otro delta de este snapshot en estado «ejecutando». No se inicia otro.');
        }

        $this->codigosActualizar = [];
        $this->codigosRechazados = [];
        $corte = $this->snapshotDeCorte();
        $plan = $this->planear($corte);
        $this->rechazarDiferencias($corte);
        $this->rechazarGruposExistentes($plan);
        $this->rechazarParticipantesSinEvento($plan);

        $resumen = [
            'snapshot_corte' => $corte,
            'eventos_nuevos' => count($plan['eventos']),
            'participantes_nuevos' => count($plan['participantes']),
            'descargas_nuevas' => count($plan['descargas']),
            'imagenes_nuevas' => count($plan['imagenes']),
            'tokens_nuevos_no_migrados' => $this->stg()->table('stg_ev_token')->where('primera_snapshot_id', '>', $corte)->where('estado_fila', '!=', self::AUSENTE)->count(),
            'codigos_legado_actualizados' => count($this->codigosActualizar),
        ];
        if ($plan['eventos'] === [] && $plan['participantes'] === [] && $plan['descargas'] === [] && $plan['imagenes'] === [] && $this->codigosActualizar === []) {
            return new ResultadoMigracion(true, 0, $resumen, 'No hay filas nuevas posteriores al último snapshot migrado. No se hizo ningún cambio.');
        }
        if ($simular) {
            return new ResultadoMigracion(true, 0, $resumen + ['simulacion' => true], 'Simulación: el delta se puede aplicar tal como está. No se escribió nada.');
        }

        $corrida = MigracionCorrida::create([
            'tipo' => MigracionCorrida::TIPO_LEGADO_DELTA, 'snapshot_sha256' => $snapshotSha, 'huella_global' => $huellaGlobal, 'huella_derivada' => $huellaDerivada,
            'estado' => MigracionCorrida::ESTADO_EJECUTANDO, 'iniciado_at' => now(),
        ]);
        $this->corridaId = $corrida->id;
        $this->ahora = now()->format('Y-m-d H:i:s');

        try {
            DB::transaction(function () use ($inicio, $plan, $resumen, $snapshotSha, $huellaGlobal, $huellaDerivada, $tomadoAt, $base) {
                $this->migrar($plan);
                $this->totales += $resumen;
                $this->totales['huellas'] = ['snapshot_sha256' => $snapshotSha, 'global' => $huellaGlobal, 'derivada' => $huellaDerivada, 'snapshot_tomado_at' => $tomadoAt, 'corrida_base' => $base->id];
                $this->totales['validaciones'] = $this->validaciones;
                $this->totales['tiempos_ms'] = array_map(fn ($t) => (int) round($t), $this->tiempos) + ['total' => (int) round((microtime(true) - $inicio) * 1000)];
                MigracionCorrida::whereKey($this->corridaId)->update([
                    'estado' => MigracionCorrida::ESTADO_COMPLETADA, 'finalizado_at' => now(),
                    'totales' => json_encode($this->totales + ['huella_clave' => HuellaClave::actual(), 'proceso' => 'legado-delta-11f'], JSON_UNESCAPED_UNICODE),
                ]);
            });
        } catch (Throwable $e) {
            MigracionCorrida::whereKey($this->corridaId)->update([
                'estado' => MigracionCorrida::ESTADO_FALLIDA, 'finalizado_at' => now(), 'error_codigo' => mb_substr($e::class, 0, 120),
                'totales' => json_encode(['validaciones' => $this->validaciones], JSON_UNESCAPED_UNICODE),
            ]);

            throw $e;
        }

        return new ResultadoMigracion(false, $this->corridaId, $this->totales, "Delta completado (corrida #{$this->corridaId}).");
    }

    /**
     * SOLO LECTURA (la usa la simulación de `encuestas:delta`): old_id de los eventos que este delta crearía, tras pasar las MISMAS
     * negaciones que `ejecutar()` antes de escribir. No escribe nada ni asigna ningún destino. Lanza DeltaRechazado si el plan está bloqueado.
     *
     * @return array<int,string>
     */
    public function eventosPlanificados(): array
    {
        $this->codigosActualizar = [];
        $this->codigosRechazados = [];
        $corte = $this->snapshotDeCorte();
        $plan = $this->planear($corte);
        $this->rechazarDiferencias($corte);
        $this->rechazarGruposExistentes($plan);
        $this->rechazarParticipantesSinEvento($plan);

        $ids = array_map(fn ($e) => (string) $e->old_id, $plan['eventos']);
        if (count($ids) !== count(array_unique($ids))) {
            throw new DeltaRechazado(DeltaRechazado::EVENTO_NO_MIGRADO, 'El plan de certificados tiene un evento repetido: el mapeo futuro es ambiguo.');
        }

        return $ids;
    }

    // ── Huellas, corte y plan ─────────────────────────────────────────────────────────────────────────────

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

    /** Id del staging del ÚLTIMO snapshot ya incorporado (base o delta anterior): lo posterior a él es el delta. */
    private function snapshotDeCorte(): int
    {
        $shas = MigracionCorrida::whereIn('tipo', [MigracionCorrida::TIPO_LEGADO, MigracionCorrida::TIPO_LEGADO_DELTA])
            ->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->pluck('snapshot_sha256')->all();
        $ids = $this->stg()->table('stg_ev_snapshots')->whereIn('dump_sha256', $shas)->pluck('id')->all();
        if ($ids === []) {
            throw new DeltaRechazado(DeltaRechazado::SIN_BASE, 'El staging no contiene el snapshot de la corrida base: no se puede determinar qué es nuevo.');
        }

        return (int) max($ids);
    }

    /** @return array<string,string> origen_id → destino_id de lo ya migrado por la base o por un delta anterior */
    private function mapeados(string $origenTabla): array
    {
        return DB::table('cf_migraciones_map as m')->join('cf_migraciones_corridas as c', 'c.id', '=', 'm.corrida_id')
            ->whereIn('c.tipo', [MigracionCorrida::TIPO_LEGADO, MigracionCorrida::TIPO_LEGADO_DELTA])->where('c.estado', MigracionCorrida::ESTADO_COMPLETADA)
            ->where('m.origen_tabla', $origenTabla)->pluck('m.destino_id', 'm.origen_id')->all();
    }

    /**
     * @return array{eventos:array<int,object>,participantes:array<int,object>,descargas:array<int,object>,imagenes:array<int,object>,
     *   mapaEventos:array<string,string>,mapaParticipantes:array<string,string>}
     */
    private function planear(int $corte): array
    {
        $s = $this->stg();
        $mapaEventos = $this->mapeados('evento');
        $mapaParticipantes = $this->mapeados('participante');
        $mapaDescargas = $this->mapeados('descarga');
        $mapaImagenes = $this->mapeados('imagen');

        $nuevas = fn (string $tabla) => $s->table($tabla)->where('primera_snapshot_id', '>', $corte)->where('estado_fila', '!=', self::AUSENTE);

        $eventos = $nuevas('stg_ev_evento')->orderBy('old_id')->get()->filter(fn ($e) => ! isset($mapaEventos[(string) $e->old_id]))->values()->all();
        $participantes = $nuevas('stg_ev_participante')->orderBy('old_id')->get()->filter(fn ($p) => ! isset($mapaParticipantes[(string) $p->old_id]))->values()->all();
        $descargas = $nuevas('stg_ev_descargas')->orderBy('old_id')->get()->filter(fn ($d) => ! isset($mapaDescargas[(string) $d->old_id]))->values()->all();
        $imagenes = $nuevas('stg_ev_imagenes')->orderBy('id')->get()->filter(fn ($i) => ! isset($mapaImagenes[(string) $i->ruta_relativa]))->values()->all();

        return compact('eventos', 'participantes', 'descargas', 'imagenes', 'mapaEventos', 'mapaParticipantes');
    }

    // ── Negaciones (antes de escribir) ────────────────────────────────────────────────────────────────────

    private function rechazarDiferencias(int $corte): void
    {
        $s = $this->stg();
        $cambiadas = [];
        $ausentes = [];
        foreach (self::VIGILADAS as $entidad => [$tabla, $col]) {
            // Una fila que ya existía antes del corte y cambió o desapareció (la fila nueva no puede estar «cambiada»).
            $claves = $s->table($tabla)->where('primera_snapshot_id', '<=', $corte)->where('estado_fila', 'cambiada')->pluck($col)->map(fn ($v) => (string) $v)->all();
            $enHistorial = $s->table('stg_ev_historial')->where('entidad', $entidad)->where('snapshot_id', '>', $corte)
                ->get(['old_id', 'clave'])->map(fn ($h) => (string) ($h->old_id ?? $h->clave))->all();
            $todas = array_values(array_unique([...$claves, ...$enHistorial]));
            if ($entidad === 'participante' && $todas !== []) {
                // Única transición base aceptada: `num_verificacion` NULL/vacío → código (con TODAS sus invariantes).
                $todas = $this->evaluarCambiosDeCodigo($todas, $corte);
            }
            if ($todas !== []) {
                $cambiadas[$entidad] = count($todas);
            }
            $n = $s->table($tabla)->where('primera_snapshot_id', '<=', $corte)->where('estado_fila', self::AUSENTE)->count();
            if ($n > 0) {
                $ausentes[$entidad] = $n;
            }
        }
        if ($cambiadas !== []) {
            throw new DeltaRechazado(DeltaRechazado::FILAS_CAMBIADAS, 'Hay filas ya migradas que cambiaron en el origen ('.json_encode($cambiadas).'). El delta no actualiza ni reconcilia: se detiene.', ['cambiadas' => $cambiadas, 'ausentes' => $ausentes, 'cambios_de_codigo_rechazados' => $this->codigosRechazados]);
        }
        if ($ausentes !== []) {
            throw new DeltaRechazado(DeltaRechazado::FILAS_AUSENTES, 'Hay filas ya migradas que ya no existen en el origen ('.json_encode($ausentes).'). El delta no borra ni reconcilia: se detiene.', ['ausentes' => $ausentes, 'cambiadas' => $cambiadas]);
        }
    }

    /** @param array<string,mixed> $plan */
    private function rechazarGruposExistentes(array $plan): void
    {
        $gids = collect($plan['participantes'])->pluck('grupo_duplicado_id')->filter()->unique()->values()->all();
        if ($gids === []) {
            return;
        }
        $nuevos = collect($plan['participantes'])->pluck('old_id')->map(fn ($v) => (int) $v)->all();
        $nuevosAfectados = 0;
        $existentes = [];
        foreach ($this->stg()->table('stg_ev_duplicados')->whereIn('id', $gids)->get(['id', 'old_ids']) as $g) {
            $ids = array_map('intval', json_decode((string) $g->old_ids, true) ?: []);
            $viejos = array_values(array_filter($ids, fn ($i) => ! in_array($i, $nuevos, true)));
            $enGrupo = array_values(array_filter($ids, fn ($i) => in_array($i, $nuevos, true)));
            if ($viejos !== []) {
                $nuevosAfectados += count($enGrupo);
                foreach ($viejos as $v) {
                    $existentes[$v] = true;
                }
            }
        }
        if ($nuevosAfectados > 0) {
            throw new DeltaRechazado(
                DeltaRechazado::GRUPO_EXISTENTE,
                "Hay {$nuevosAfectados} participante(s) nuevo(s) que caen en grupos de duplicados ya existentes: requiere una ruta controlada (recalcular el grupo, detector y relaciones). No se actualiza nada en silencio.",
                ['participantes_nuevos_en_grupos_existentes' => $nuevosAfectados, 'certificados_existentes_afectados' => count($existentes)]
            );
        }
    }

    /** @param array<string,mixed> $plan */
    private function rechazarParticipantesSinEvento(array $plan): void
    {
        $nuevosEventos = array_map(fn ($e) => (string) $e->old_id, $plan['eventos']);
        $sin = 0;
        foreach ($plan['participantes'] as $p) {
            if (! isset($plan['mapaEventos'][(string) $p->old_evento_id]) && ! in_array((string) $p->old_evento_id, $nuevosEventos, true)) {
                $sin++;
            }
        }
        if ($sin > 0) {
            throw new DeltaRechazado(DeltaRechazado::PARTICIPANTE_SIN_EVENTO, "Hay {$sin} participante(s) nuevo(s) en un evento que no existe (no se inventa).", ['participantes' => $sin]);
        }
    }

    // ── Cambio de `num_verificacion` NULL → código sobre un certificado base ───────────────────────────────

    /**
     * Evalúa los participantes ya migrados que cambiaron en el origen. Acepta (y guarda en `$codigosActualizar`) SOLO los que cumplen
     * TODAS las invariantes; devuelve los old_id que NO se aceptan (siguen siendo FILAS_CAMBIADAS). Solo lectura: no escribe nada.
     *
     * @param  array<int,string>  $oldIds
     * @return array<int,string> old_id rechazados
     */
    private function evaluarCambiosDeCodigo(array $oldIds, int $corte): array
    {
        $rechazados = [];
        foreach ($oldIds as $old) {
            $motivo = $this->evaluarCambioDeCodigo((int) $old, $corte);
            if ($motivo !== null) {
                $rechazados[] = (string) $old;
                $this->codigosRechazados[$motivo] = ($this->codigosRechazados[$motivo] ?? 0) + 1;
            }
        }

        return $rechazados;
    }

    /** @return string|null motivo técnico del rechazo (sin datos personales), o null si la transición es aceptable */
    private function evaluarCambioDeCodigo(int $old, int $corte): ?string
    {
        $s = $this->stg();
        $fila = $s->table('stg_ev_participante')->where('old_id', $old)->first();
        if ($fila === null || $fila->estado_fila === self::AUSENTE) {
            return 'SIN_FILA_ACTUAL';
        }
        $hist = $s->table('stg_ev_historial')->where('entidad', 'participante')->where('old_id', $old)->where('snapshot_id', '>', $corte)->orderBy('id')->first();
        if ($hist === null) {
            return 'SIN_HISTORIAL';
        }
        $previo = json_decode((string) $hist->datos_anteriores, true);
        if (! is_array($previo) || ! array_key_exists('num_verificacion', $previo)) {
            return 'SIN_HISTORIAL';
        }

        // 1. El único campo cambiado es num_verificacion (todo lo demás que guarda el staging es idéntico).
        foreach (self::ORIGINALES_SIN_CODIGO as $colStaging => $colOrigen) {
            $a = $previo[$colOrigen] ?? null;
            $b = $fila->{$colStaging};
            if (($a === null ? null : (string) $a) !== ($b === null ? null : (string) $b)) {
                return 'CAMPOS_ADICIONALES';
            }
        }
        // 2-3. Valor anterior vacío; valor final no vacío.
        $vacio = fn ($v) => $v === null || trim((string) $v) === '';
        if (! $vacio($previo['num_verificacion'])) {
            return 'CODIGO_PREVIO_NO_VACIO';
        }
        if ($vacio($fila->num_verificacion)) {
            return 'CODIGO_FINAL_VACIO';
        }
        $codigo = trim((string) $fila->num_verificacion);
        if (preg_match('/^\d{1,5}$/', $codigo) !== 1) {
            return 'CODIGO_FORMATO';
        }

        // 6. Fuera del rango reservado de Credential Flow.
        [$inicio, $fin] = $this->rangoReservado();
        if ((int) $codigo >= $inicio && (int) $codigo <= $fin) {
            return 'RANGO_RESERVADO';
        }

        // 4-5. Único: ningún otro participante del sistema viejo, ningún certificado migrado y ningún código moderno lo usa.
        if ($s->table('stg_ev_participante')->where('num_verificacion', (int) $codigo)->where('old_id', '!=', $old)->where('estado_fila', '!=', self::AUSENTE)->exists()) {
            return 'CODIGO_DUPLICADO_LEGACY';
        }
        if (DB::table('cf_certificados_legado')->where('codigo_legado', $codigo)->exists()) {
            return 'CODIGO_COLISIONA_CERTIFICADO';
        }
        if (DB::table('cf_codigos_historicos')->whereIn('codigo', array_values(array_unique([$codigo, str_pad($codigo, 5, '0', STR_PAD_LEFT)])))->exists()) {
            return 'CODIGO_COLISIONA_MODERNO';
        }

        // 7. El certificado base: existe, es el esperado y está «limpio».
        $certId = $this->mapeados('participante')[(string) $old] ?? null;
        $cert = $certId === null ? null : DB::table('cf_certificados_legado')->where('id', $certId)->first();
        if ($cert === null) {
            return 'SIN_CERTIFICADO';
        }
        $snap = json_decode((string) $cert->snapshot_legado, true) ?: [];
        if ((int) ($snap['migracion']['old_id'] ?? 0) !== $old) {
            return 'MAPEO_INCORRECTO';
        }
        $eventoDestino = $this->mapeados('evento')[(string) $fila->old_evento_id] ?? null;
        if ($eventoDestino === null || (int) $eventoDestino !== (int) $cert->evento_id) {
            return 'EVENTO_DISTINTO';
        }
        if ($cert->codigo_legado !== null && trim((string) $cert->codigo_legado) !== '') {
            return 'CERTIFICADO_YA_TIENE_CODIGO';
        }
        if ($cert->estado !== CertificadoLegado::ESTADO_VIGENTE || $cert->revocado_at !== null) {
            return 'CERTIFICADO_NO_VIGENTE';
        }
        if ($cert->pdf_archivo !== null || $cert->pdf_hash !== null || $cert->materializado_at !== null) {
            return 'PDF_CONGELADO';
        }
        if ((bool) $cert->visible_portal) {
            return 'VISIBLE_EN_PORTAL';
        }
        if ($cert->reemplazado_por_emision_id !== null || (int) $cert->intentos_generacion > 0
            || DB::table('cf_descargas')->where('certificado_legado_id', $cert->id)->whereNotNull('emision_id')->exists()) {
            return 'EMISION_MODERNA';
        }
        if (DB::table('cf_codigos_historicos')->where('certificado_canonico_id', $cert->id)->exists()) {
            return 'CODIGO_MODERNO_ASIGNADO';
        }
        $corridaCert = $cert->corrida_id === null ? null : MigracionCorrida::find($cert->corrida_id);
        if ($cert->update_by !== null || $corridaCert === null || $corridaCert->finalizado_at === null
            || (string) $cert->updated_at > $corridaCert->finalizado_at->format('Y-m-d H:i:s')) {
            return 'MODIFICADO_POSTERIORMENTE';
        }

        // 8. Grupo (duplicados) sin cambios: ni el certificado ni el staging pertenecen a un grupo de duplicados.
        if ($cert->grupo_duplicado !== null || $fila->grupo_duplicado_id !== null) {
            return 'EN_GRUPO_DUPLICADO';
        }
        // Un par (evento, documento) no puede quedar con dos códigos distintos.
        if ($cert->documento_clave !== null && DB::table('cf_certificados_legado')->where('evento_id', $cert->evento_id)->where('documento_clave', $cert->documento_clave)
            ->where('id', '!=', $cert->id)->whereNotNull('codigo_legado')->where('codigo_legado', '!=', $codigo)->exists()) {
            return 'PAR_CON_VARIOS_CODIGOS';
        }

        $this->codigosActualizar[$old] = ['cert_id' => (int) $cert->id, 'codigo' => $codigo, 'evento_id' => (int) $cert->evento_id];

        return null;
    }

    /** @return array{0:int,1:int} */
    private function rangoReservado(): array
    {
        $fila = DB::table('cf_codigo_historico_contador')->where('id', 1)->first();
        if ($fila !== null) {
            return [(int) $fila->inicio, (int) $fila->fin];
        }

        return [(int) config('credential_flow.codigos_historicos.inicio', 50000), (int) config('credential_flow.codigos_historicos.fin', 99999)];
    }

    /** Aplica las actualizaciones aceptadas: UNA fila por certificado, acotada por id y por `codigo_legado IS NULL`. */
    private function actualizarCodigos(): void
    {
        $n = 0;
        foreach ($this->codigosActualizar as $old => $a) {
            $antes = DB::table('cf_certificados_legado')->where('id', $a['cert_id'])->first(['updated_at', 'codigo_legado']);
            $casos = DB::table('cf_conciliaciones_certificados as cc')->join('cf_conciliaciones as c', 'c.id', '=', 'cc.conciliacion_id')
                ->where('cc.certificado_legado_id', $a['cert_id'])->orderBy('c.id')->get(['c.id', 'c.estado', 'c.updated_at'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'estado' => $c->estado, 'updated_at' => (string) $c->updated_at])->all();

            $filas = DB::table('cf_certificados_legado')->where('id', $a['cert_id'])->whereNull('codigo_legado')
                ->update(['codigo_legado' => $a['codigo'], 'updated_at' => $this->ahora]);
            if ($filas !== 1) {
                throw new RuntimeException('La actualización de código legado afectó '.$filas.' filas (se esperaba 1). Se revierte toda la corrida.');
            }
            $this->mapa(self::MAPA_CODIGO, (string) $old, 'cf_certificados_legado', $a['cert_id'], 'codigo_actualizado', [
                'codigo_anterior' => null, 'codigo_nuevo' => $a['codigo'], 'updated_at_anterior' => (string) $antes->updated_at, 'updated_at_nuevo' => $this->ahora,
                'casos_vinculados' => $casos,
            ]);
            $n++;
        }
        $this->totales['codigos_actualizados'] = ['certificados' => $n, 'cambios_rechazados' => $this->codigosRechazados];
    }

    // ── Migración (dentro de la transacción) ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $plan */
    private function migrar(array $plan): void
    {
        $etapa = function (string $nombre, callable $f) {
            $t = microtime(true);
            $f();
            $this->tiempos[$nombre] = (microtime(true) - $t) * 1000;
        };

        $entradas = [];     // id de imagen del staging → [id entrada, estado, renderizable]
        $faltantes = [];    // ruta original faltante → id entrada
        $eventos = [];      // old_evento_id → [id evento, plantilla usable]
        $certificados = []; // old_participante_id → id (SOLO los de este delta)
        $correosOriginal = [];

        $etapa('contenidos_y_plantillas', function () use ($plan, &$entradas, &$faltantes) {
            $this->plantillas($plan, $entradas, $faltantes);
        });
        $etapa('eventos', function () use ($plan, $entradas, $faltantes, &$eventos) {
            $this->eventos($plan, $entradas, $faltantes, $eventos);
        });
        $etapa('certificados', function () use ($plan, &$eventos, &$certificados, &$correosOriginal) {
            $this->certificados($plan, $eventos, $certificados, $correosOriginal);
        });
        $etapa('correos', function () use ($certificados, $correosOriginal) {
            $this->correos($certificados, $correosOriginal);
        });
        $etapa('descargas', function () use ($plan, $certificados) {
            $this->descargas($plan, $certificados);
        });
        $etapa('codigos', function () {
            $this->actualizarCodigos();
        });
        $this->vaciarMapa();

        $this->totales['tokens'] = ['migrados' => 0, 'destino' => 'NO_MIGRAR (los reemplaza el OTP; quedan solo en staging)'];
        $this->validar($plan);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,array{0:int,1:string,2:bool}>  $entradas
     * @param  array<string,int>  $faltantes
     */
    private function plantillas(array $plan, array &$entradas, array &$faltantes): void
    {
        $s = $this->stg();
        $existentes = DB::table('cf_plantillas_legado_contenidos')->pluck('id', 'sha256')->all();
        $nuevosContenidos = 0;
        $reutilizados = 0;
        $eventoPorImagen = $s->table('stg_ev_evento')->where('estado_fila', '!=', self::AUSENTE)->whereNotNull('imagen_ref_id')->get()->keyBy('imagen_ref_id');
        $evidencia = $plan['imagenes'] === [] ? [] : $this->evidenciaCandidatas();
        $porEstado = ['ok' => 0, 'faltante' => 0, 'huerfana' => 0, 'candidata_revision' => 0, 'extension_invalida' => 0];

        foreach ($plan['imagenes'] as $img) {
            if (! isset($existentes[$img->sha256])) {
                $existentes[$img->sha256] = DB::table('cf_plantillas_legado_contenidos')->insertGetId([
                    'sha256' => $img->sha256, 'bytes' => $img->bytes, 'mime_real' => $img->mime_real, 'ancho_px' => $img->ancho_px, 'alto_px' => $img->alto_px,
                    'ruta_almacenada' => null, 'corrida_id' => $this->corridaId, 'created_at' => $this->ahora, 'updated_at' => $this->ahora,
                ]);
                $this->mapa('imagen_contenido', $img->sha256, 'cf_plantillas_legado_contenidos', $existentes[$img->sha256]);
                $nuevosContenidos++;
            } else {
                $reutilizados++;
            }

            $evento = $eventoPorImagen[$img->id] ?? null;
            [$estado, $notas] = $this->estadoDeEntrada($img, $evento);
            $porEstado[$estado]++;
            $id = DB::table('cf_plantillas_legado')->insertGetId([
                'contenido_id' => $existentes[$img->sha256], 'ruta_original' => $img->ruta_relativa, 'nombre_original' => $img->nombre_original,
                'nombre_normalizado' => Normalizador::claveArchivo((string) $img->nombre_original), 'extension_original' => $img->extension,
                'renderizable' => (bool) $img->renderizable_fpdf, 'motivo_no_renderizable' => $img->motivo_no_renderizable, 'estado' => $estado, 'notas' => $notas,
                'corrida_id' => $this->corridaId, 'created_at' => $this->ahora, 'updated_at' => $this->ahora,
            ]);
            $entradas[$img->id] = [$id, $estado, (bool) $img->renderizable_fpdf];
            $this->mapa('imagen', $img->ruta_relativa, 'cf_plantillas_legado', $id);
        }

        // Nombres de imagen SIN archivo de los eventos nuevos (la imagen no existe o su extensión es inválida): se reutiliza la entrada
        // si ya existe por nombre; si no, se registra sin contenido (nunca se inventa plantilla).
        $conocidas = DB::table('cf_plantillas_legado')->whereNull('contenido_id')->pluck('id', 'ruta_original')->all();
        foreach ($plan['eventos'] as $e) {
            if ($e->imagen_ref_id !== null || ! in_array($e->imagen_estado, ['archivo_faltante', 'extension_invalida'], true) || $e->imagen_original === null) {
                continue;
            }
            $ruta = EscanerImagenes::PREFIJO_RUTA.$e->imagen_original;
            if (isset($faltantes[$ruta])) {
                continue;
            }
            if (isset($conocidas[$ruta])) {
                $faltantes[$ruta] = (int) $conocidas[$ruta];

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

        $this->totales['contenidos'] = ['nuevos' => $nuevosContenidos, 'reutilizados_por_sha' => $reutilizados];
        $this->totales['plantillas'] = array_sum($porEstado);
        $this->totales['plantillas_por_estado'] = $porEstado;
    }

    /** @return array{0:string,1:?string} */
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

    /** @return array<int,array<string,mixed>> */
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

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,array{0:int,1:string,2:bool}>  $entradas
     * @param  array<string,int>  $faltantes
     * @param  array<int,array{0:int,1:?int}>  $eventos
     */
    private function eventos(array $plan, array $entradas, array $faltantes, array &$eventos): void
    {
        // Eventos YA migrados (a los que pueden pertenecer participantes nuevos): su id destino y su plantilla usable.
        foreach ($plan['mapaEventos'] as $oldId => $destinoId) {
            $eventos[(int) $oldId] = [(int) $destinoId, null];
        }
        if ($plan['mapaEventos'] !== []) {
            $info = DB::table('cf_eventos as e')->leftJoin('cf_plantillas_legado as p', 'p.id', '=', 'e.plantilla_legado_id')
                ->whereIn('e.id', array_map('intval', array_values($plan['mapaEventos'])))->get(['e.id', 'e.plantilla_legado_id', 'p.estado', 'p.renderizable'])->keyBy('id');
            foreach ($plan['mapaEventos'] as $oldId => $destinoId) {
                $i = $info[(int) $destinoId] ?? null;
                $eventos[(int) $oldId] = [(int) $destinoId, ($i !== null && $i->plantilla_legado_id !== null && $i->estado === 'ok' && (bool) $i->renderizable) ? (int) $i->plantilla_legado_id : null];
            }
        }

        // Entradas de plantilla de las imágenes que referencian los eventos nuevos y que NO son nuevas (ya existían por nombre).
        $porRuta = [];
        $refs = array_values(array_unique(array_filter(array_map(fn ($e) => $e->imagen_ref_id, $plan['eventos']))));
        if ($refs !== []) {
            $imgs = $this->stg()->table('stg_ev_imagenes')->whereIn('id', $refs)->get(['id', 'ruta_relativa'])->all();
            $existentes = DB::table('cf_plantillas_legado')->whereIn('ruta_original', array_map(fn ($i) => $i->ruta_relativa, $imgs))->get(['id', 'ruta_original', 'estado', 'renderizable'])->keyBy('ruta_original');
            foreach ($imgs as $i) {
                if (! isset($entradas[$i->id]) && isset($existentes[$i->ruta_relativa])) {
                    $x = $existentes[$i->ruta_relativa];
                    $porRuta[$i->id] = [(int) $x->id, (string) $x->estado, (bool) $x->renderizable];
                }
            }
        }

        $pendientes = 0;
        $sinAnio = ['ANIO_NO_DEDUCIBLE' => 0, 'ANIO_AMBIGUO' => 0];
        foreach ($plan['eventos'] as $e) {
            $plantilla = null;
            $usable = null;
            $marcas = [];
            if ($e->imagen_ref_id !== null) {
                [$plantilla, $estado, $renderizable] = $entradas[$e->imagen_ref_id] ?? $porRuta[$e->imagen_ref_id] ?? throw new RuntimeException('Evento con imagen_ref_id sin entrada de plantilla.');
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
                $marcas[] = $codigo;
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

        $this->totales['eventos'] = count($plan['eventos']);
        $this->totales['eventos_pendientes_de_plantilla'] = $pendientes;
        $this->totales['eventos_sin_anio'] = $sinAnio;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,array{0:int,1:?int}>  $eventos
     * @param  array<int,int>  $certificados
     * @param  array<int,array{0:?string,1:int}>  $correosOriginal
     */
    private function certificados(array $plan, array $eventos, array &$certificados, array &$correosOriginal): void
    {
        $gids = collect($plan['participantes'])->pluck('grupo_duplicado_id')->filter()->unique()->values()->all();
        $grupos = [];
        if ($gids !== []) {
            foreach ($this->stg()->table('stg_ev_duplicados')->whereIn('id', $gids)->get() as $g) {
                $ids = json_decode((string) $g->old_ids, true) ?: [];
                $grupos[(int) $g->id] = ['clasificacion' => $g->clasificacion, 'etiquetas' => $g->etiquetas, 'subtipo' => $g->subtipo, 'old_ids' => array_map('intval', $ids), 'canonico' => $ids === [] ? null : (int) min($ids)];
            }
        }

        $c = ['por_conciliacion' => [], 'grupos_vistos' => [], 'filas_identicas' => 0, 'filas_conflictivas' => 0, 'tipo_vacio' => 0, 'con_codigo' => 0, 'sin_plantilla_usable' => 0];
        $codigos = [];

        foreach ($plan['participantes'] as $p) {
            [$eventoId, $usable] = $eventos[(int) $p->old_evento_id] ?? throw new RuntimeException('Participante en un evento que no existe (no se inventa).');
            $g = $p->grupo_duplicado_id === null ? null : ($grupos[(int) $p->grupo_duplicado_id] ?? throw new RuntimeException('Participante en un grupo de duplicados que no existe.'));

            $revision = $p->documento_estado !== 'valido';
            $conflictivo = $g !== null && $g['clasificacion'] === 'conflictivo';
            $identicoNoCanonico = $g !== null && $g['clasificacion'] === 'identico' && (int) $p->old_id !== $g['canonico'];
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
                'migracion' => ['origen' => 'evaluaciones', 'old_id' => (int) $p->old_id, 'old_evento_id' => (int) $p->old_evento_id, 'norm_version' => (int) $p->norm_version, 'hash_fila' => $p->hash_fila, 'delta' => true],
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
                // Las etiquetas de grupo del staging se renumeran en cada carga: el delta usa una etiqueta propia y estable (corrida + grupo).
                'grupo_duplicado' => $g === null ? null : 'dup-d'.$this->corridaId.'-'.$p->grupo_duplicado_id, 'visible_portal' => false, 'intentos_generacion' => 0,
                'corrida_id' => $this->corridaId, 'created_at' => $this->ahora, 'updated_at' => $this->ahora,
            ]);
            $certificados[(int) $p->old_id] = $id;
            if ((int) $p->correos_candidatos > 0) {
                $correosOriginal[(int) $p->old_id] = [$p->correo_original, (int) $p->correos_candidatos];
            }

            $relacion = $conflictivo ? 'variante_conflictiva' : ($g === null ? 'principal' : ((int) $p->old_id === $g['canonico'] ? 'canonico' : 'duplicado_identico'));
            $this->mapa('participante', (string) $p->old_id, 'cf_certificados_legado', $id, $relacion, $g === null ? null : ['grupo_staging' => (int) $p->grupo_duplicado_id, 'canonico_old_id' => $g['canonico']]);
        }

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

    /**
     * @param  array<int,int>  $certificados
     * @param  array<int,array{0:?string,1:int}>  $correosOriginal
     */
    private function correos(array $certificados, array $correosOriginal): void
    {
        $n = ['total' => 0, 'valido' => 0, 'invalido' => 0, 'normalizado_distinto' => 0];
        if ($certificados !== []) {
            foreach (array_chunk(array_keys($certificados), 1000) as $ids) {
                $lote = [];
                foreach ($this->stg()->table('stg_ev_participante_correos')->whereIn('participante_old_id', $ids)->orderBy('id')->get() as $f) {
                    $cert = $certificados[(int) $f->participante_old_id] ?? throw new RuntimeException('Candidato de correo sin certificado.');
                    [$original, $candidatos] = $correosOriginal[(int) $f->participante_old_id] ?? [null, 0];
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
            }
        }
        $this->totales['correos'] = $n;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,int>  $certificadosDelta
     */
    private function descargas(array $plan, array $certificadosDelta): void
    {
        $n = ['migradas' => 0, 'sin_certificado' => 0, 'sobre_certificados_base' => 0, 'sobre_certificados_delta' => 0];
        $ids = [];
        $lote = [];
        $base = $plan['mapaParticipantes'];

        foreach ($plan['descargas'] as $d) {
            $old = $d->old_participante_id === null ? null : (int) $d->old_participante_id;
            $cert = null;
            $origen = null;
            if ($old !== null && isset($certificadosDelta[$old])) {
                $cert = (int) $certificadosDelta[$old];
                $origen = 'sobre_certificados_delta';
            } elseif ($old !== null && isset($base[(string) $old])) {
                $cert = (int) $base[(string) $old];
                $origen = 'sobre_certificados_base';
            }
            if ($cert === null) {
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
            $n[$origen]++;
            $this->mapa('descarga', (string) $d->old_id, 'cf_descargas', $id);
        }
        unset($lote);

        $this->totales['descargas'] = $n + ['old_ids_sin_certificado' => $ids];
    }

    // ── Validación contra el subconjunto delta del staging ────────────────────────────────────────────────

    /** @param array<string,mixed> $plan */
    private function validar(array $plan): void
    {
        $mio = fn (string $t) => DB::table($t)->where('corrida_id', $this->corridaId);
        $idsPart = array_map(fn ($p) => (int) $p->old_id, $plan['participantes']);
        $correosStg = $idsPart === [] ? collect() : $this->stg()->table('stg_ev_participante_correos')->whereIn('participante_old_id', $idsPart)->get(['estado']);
        $cert = $mio('cf_certificados_legado');

        $conCodigo = collect($plan['participantes'])->filter(fn ($p) => $p->num_verificacion !== null);
        $descargasConParticipante = collect($plan['descargas'])->filter(function ($d) use ($idsPart, $plan) {
            return $d->old_participante_id !== null && (in_array((int) $d->old_participante_id, $idsPart, true) || isset($plan['mapaParticipantes'][(string) $d->old_participante_id]));
        })->count();
        $contenidosNuevos = collect($plan['imagenes'])->pluck('sha256')->unique()
            ->reject(fn ($sha) => DB::table('cf_plantillas_legado_contenidos')->where('sha256', $sha)->where(fn ($q) => $q->whereNull('corrida_id')->orWhere('corrida_id', '!=', $this->corridaId))->exists())->count();

        $esperado = [
            'eventos' => [count($plan['eventos']), $mio('cf_eventos')->count()],
            'contenidos_nuevos_distintos' => [$contenidosNuevos, $mio('cf_plantillas_legado_contenidos')->count()],
            'certificados' => [count($plan['participantes']), (clone $cert)->count()],
            'certificados_revision_documento' => [collect($plan['participantes'])->where('documento_estado', '!=', 'valido')->count(), (clone $cert)->where('conciliacion_estado', 'revision_documento')->count()],
            'certificados_visible_portal' => [0, (clone $cert)->where('visible_portal', true)->count()],
            'certificados_con_pdf' => [0, (clone $cert)->where(fn ($q) => $q->whereNotNull('pdf_archivo')->orWhereNotNull('materializado_at'))->count()],
            'correos_candidatos' => [$correosStg->count(), $mio('cf_correos')->count()],
            'correos_validos' => [$correosStg->where('estado', 'valido')->count(), $mio('cf_correos')->where('estado', 'valido')->count()],
            'correos_invalidos' => [$correosStg->where('estado', 'invalido')->count(), $mio('cf_correos')->where('estado', 'invalido')->count()],
            'descargas' => [$descargasConParticipante, $mio('cf_descargas')->count()],
            'filas_con_codigo' => [$conCodigo->count(), (clone $cert)->whereNotNull('codigo_legado')->count()],
            'mapa_participantes' => [count($plan['participantes']), DB::table('cf_migraciones_map')->where('corrida_id', $this->corridaId)->where('origen_tabla', 'participante')->count()],
            'mapa_eventos' => [count($plan['eventos']), DB::table('cf_migraciones_map')->where('corrida_id', $this->corridaId)->where('origen_tabla', 'evento')->count()],
            'mapa_descargas' => [$descargasConParticipante, DB::table('cf_migraciones_map')->where('corrida_id', $this->corridaId)->where('origen_tabla', 'descarga')->count()],
            'codigos_actualizados' => [count($this->codigosActualizar), DB::table('cf_migraciones_map')->where('corrida_id', $this->corridaId)->where('origen_tabla', self::MAPA_CODIGO)->count()],
            // Un código legado de este delta no puede coincidir con el de otro par (evento, documento) ya migrado ni con otro del delta.
            'codigos_compartidos_entre_pares_distintos' => [0, $this->codigosCompartidos()],
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
            throw new RuntimeException('El delta no cuadra con el staging en: '.implode(', ', $errores).'. Se revierte toda la corrida.');
        }
    }

    private function codigosCompartidos(): int
    {
        $propios = DB::table('cf_certificados_legado')->where('corrida_id', $this->corridaId)->whereNotNull('codigo_legado')->get(['codigo_legado', 'evento_id', 'documento_clave']);
        if ($propios->isEmpty()) {
            return 0;
        }
        $pares = [];
        foreach ($propios as $r) {
            $pares[$r->codigo_legado][$r->evento_id."\0".$r->documento_clave] = true;
        }
        foreach (array_chunk(array_keys($pares), 500) as $trozo) {
            foreach (DB::table('cf_certificados_legado')->whereIn('codigo_legado', $trozo)->where('corrida_id', '!=', $this->corridaId)->get(['codigo_legado', 'evento_id', 'documento_clave']) as $r) {
                $pares[$r->codigo_legado][$r->evento_id."\0".$r->documento_clave] = true;
            }
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
