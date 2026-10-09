<?php

namespace App\Support\CredentialFlow\Identidad;

use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\ConciliacionEvento;
use App\Models\CredentialFlow\DecisionIdentidad;
use App\Models\CredentialFlow\DecisionIdentidadAprobacion;
use App\Models\CredentialFlow\DecisionIdentidadCorreo;
use App\Models\CredentialFlow\DecisionIdentidadGrupo;
use App\Models\Movimiento;
use App\Models\Usuario;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida as No;
use App\Support\CredentialFlow\Conciliaciones\ResolucionPlantillas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Registra y revoca DECISIONES DE IDENTIDAD sobre casos `identidad_ambigua` (Fase 10B-3A). REGISTRA, NO AUTORIZA: ninguna decisión cambia el portal, el
 * OTP, la sesión, el `grupo_hash`, la descarga ni la verificación (eso es 10B-3B). Tampoco toca nombres, documentos, `cf_correos` ni certificados.
 *
 * Todo ocurre en UNA transacción bajo el lock del CASO (hay un caso por documento: serializa a quienes deciden sobre el mismo documento). Las lecturas
 * de decisiones existentes son bloqueantes (lectura actual, no el snapshot de MySQL REPEATABLE READ). La integridad no depende de la interfaz: las
 * UNIQUE de la migración impiden duplicados vigentes aunque este servicio fallara.
 *
 * Compatibilidad entre decisiones VIGENTES del mismo documento:
 *  - un grupo pertenece a UN solo alcance «misma persona» y a UN solo «personas distintas» (UNIQUE);
 *  - «misma persona» y «personas distintas» no pueden afirmar y negar lo mismo (≥ 2 grupos en común);
 *  - un correo autoriza UN solo grupo (UNIQUE);
 *  - «requiere soporte» y «no resoluble» son terminales: una por caso y no conviven con decisiones sobre grupos/correos (se revoca primero).
 * La misma decisión repetida no duplica (idempotente). Crear una decisión NO cierra el caso; solo `requiere_soporte` (y `personas_distintas` sin vía
 * individual) lo pasa a «requiere soporte», y se deshace al revocar.
 */
final class DecisionesIdentidad
{
    public const ROLES = ['super-admin', 'admin'];

    public const ACCION_CREADA = 'identidad_decision_creada';

    public const ACCION_REVOCADA = 'identidad_decision_revocada';

    public const EVIDENCIA_MIN = 10;

    public const EVIDENCIA_MAX = 1000;

    /**
     * @param  array{grupos?:list<string>,grupo?:string,correo_hmac?:string,evidencia?:?string,confirmo?:bool,reforzada?:bool,evidencia_externa?:bool,alcance_masivo?:bool,fuente_evidencia?:?string}  $p
     * @return array{decision_id:int,creada:bool,tipo:string,caso_estado:string,aprobacion_pendiente?:bool}
     *
     * @throws No
     */
    public function crear(int $casoId, int $actorId, string $tipo, string $motivo, array $p = []): array
    {
        $this->actor($actorId);
        $motivo = $this->motivo($motivo);
        if (! in_array($tipo, DecisionIdentidad::TIPOS, true)) {
            throw new No(No::TIPO_NO_ADMITIDO, 'Ese tipo de decisión no existe.');
        }
        $evidencia = $this->evidenciaTexto($p['evidencia'] ?? null);

        return DB::transaction(function () use ($casoId, $actorId, $tipo, $motivo, $p, $evidencia) {
            $caso = Conciliacion::query()->whereKey($casoId)->lockForUpdate()->firstOrFail();
            if ($caso->tipo !== Conciliacion::TIPO_IDENTIDAD_AMBIGUA) {
                throw new No(No::TIPO_NO_ADMITIDO, 'Este caso no es de identidad ambigua.');
            }
            // Un caso resuelto por `identidad_aplicada` (10B-3B-2) admite nuevas decisiones (p. ej. añadir una autorización de correo o sustituir una); cualquier
            // otro caso resuelto o descartado, no.
            if (! in_array($caso->estado, [Conciliacion::ABIERTO, Conciliacion::REQUIERE_SOPORTE], true) && ! ($caso->estado === Conciliacion::RESUELTO && $caso->resolucion === AplicacionIdentidad::RESOLUCION)) {
                throw new No(No::CASO_YA_RESUELTO, 'Este caso ya fue resuelto o descartado.');
            }
            $ev = EvidenciaIdentidad::de($caso);
            if ($ev['documento_hash'] !== null && $ev['documento_hash'] !== (string) $caso->referencia_clave) {
                throw new No(No::CERTIFICADOS_CAMBIARON, 'Los certificados del caso ya no corresponden a su documento.');
            }
            $documentoHash = (string) $caso->referencia_clave;

            // Lectura ACTUAL (bloqueante) de las decisiones vigentes del documento.
            $vigentes = DecisionIdentidad::query()->where('documento_hash', $documentoHash)->where('estado', DecisionIdentidad::VIGENTE)->orderBy('id')->lockForUpdate()->get();
            $detalle = $this->detalle($vigentes);

            $d = $this->validar($tipo, $caso, $ev, $p, $evidencia);
            $clave = $this->clave((int) $caso->id, $tipo, $d['grupos'], $d['correo_hmac'], $d['grupo']);

            $igual = $vigentes->firstWhere('vigente_clave', $clave);
            if ($igual !== null) {
                return ['decision_id' => (int) $igual->id, 'creada' => false, 'tipo' => $tipo, 'caso_estado' => (string) $caso->estado];
            }
            $this->exigirCompatible($tipo, $d, $vigentes, $detalle, (int) $caso->id);

            $efecto = null;
            $estadoAnterior = (string) $caso->estado;
            $sinVia = $tipo === DecisionIdentidad::PERSONAS_DISTINTAS && collect($ev['por_hash'])->only($d['grupos'])->contains(fn ($g) => ! $g['via_individual']);
            if (($tipo === DecisionIdentidad::REQUIERE_SOPORTE || $sinVia) && $caso->estado === Conciliacion::ABIERTO) {
                $efecto = Conciliacion::REQUIERE_SOPORTE;
                $caso->update(['estado' => Conciliacion::REQUIERE_SOPORTE]);
            }

            $terminal = in_array($tipo, DecisionIdentidad::TERMINALES, true);
            $decision = new DecisionIdentidad([
                'conciliacion_id' => $caso->id, 'documento_hash' => $documentoHash, 'tipo' => $tipo, 'estado' => DecisionIdentidad::VIGENTE, 'motivo' => $motivo,
                'evidencia' => $evidencia, 'evidencia_sha256' => $evidencia === null ? null : hash('sha256', $evidencia),
                'declaro_evidencia_externa' => (bool) ($p['evidencia_externa'] ?? false), 'confirmo_alcance_masivo' => (bool) ($p['alcance_masivo'] ?? false),
                'confirmacion_reforzada' => (bool) ($p['reforzada'] ?? false), 'certificados_afectados' => $d['certificados'], 'efecto_caso' => $efecto,
                'creada_por' => $actorId, 'vigente_clave' => $clave, 'vigente_caso_clave' => $terminal ? $caso->id : null,
            ]);
            $decision->save();

            foreach ($d['grupos'] as $h) {
                DecisionIdentidadGrupo::create(['decision_id' => $decision->id, 'documento_hash' => $documentoHash, 'grupo_hash' => $h, 'vigente_tipo' => $tipo]);
            }
            if ($d['correo_hmac'] !== null) {
                DecisionIdentidadCorreo::create(['decision_id' => $decision->id, 'documento_hash' => $documentoHash, 'correo_hmac' => $d['correo_hmac'], 'correo_mascara' => $d['correo_mascara'], 'grupo_hash' => $d['grupo'], 'vigente' => 1]);
            }

            ConciliacionEvento::create([
                'conciliacion_id' => $caso->id, 'accion' => self::ACCION_CREADA, 'estado_anterior' => $estadoAnterior, 'estado_nuevo' => (string) $caso->estado, 'motivo' => $motivo, 'actor_id' => $actorId,
                'evidencia' => [
                    'decision_id' => (int) $decision->id, 'tipo' => $tipo, 'grupos' => $d['grupos'], 'grupo' => $d['grupo'], 'correo_hmac' => $d['correo_hmac'], 'certificados_afectados' => $d['certificados'],
                    'evidencia_sha256' => $decision->evidencia_sha256, 'declaro_evidencia_externa' => $decision->declaro_evidencia_externa, 'confirmo_alcance_masivo' => $decision->confirmo_alcance_masivo,
                    'efecto_caso' => $efecto, 'efecto_portal' => false,
                ] + ($tipo === DecisionIdentidad::MISMA_PERSONA ? $this->admisibilidad($ev, $d) : []),
            ]);
            $this->movimiento($actorId, self::ACCION_CREADA, (int) $caso->id, (int) $decision->id);
            // 10B-3C-3: un grupo masivo (>= 100 certificados) nace como SOLICITUD: exige la aprobación de un segundo administrador antes de ser aplicable.
            if ($d['masivo']) {
                $clave = (string) DB::table('cf_certificados_legado as k')->join('cf_conciliaciones_certificados as p', 'p.certificado_legado_id', '=', 'k.id')->where('p.conciliacion_id', $caso->id)->orderBy('k.id')->value('k.documento_clave');
                app(AutorizacionMasiva::class)->registrarSolicitud($caso, $decision, $d, (string) $d['fuente'], $clave, $actorId);
            }
            // 10B-3B-2: si la decisión produce un scope realmente autenticable (y no está diferida a 3C), el caso queda resuelto / identidad_aplicada.
            app(AplicacionIdentidad::class)->aplicarSiCorresponde($caso, $actorId);

            return ['decision_id' => (int) $decision->id, 'creada' => true, 'tipo' => $tipo, 'caso_estado' => (string) $caso->estado, 'aprobacion_pendiente' => (bool) $d['masivo']];
        });
    }

    /**
     * Revoca una decisión VIGENTE hacia adelante: no borra nada, conserva la evidencia, libera sus claves de vigencia y deshace el efecto que tuvo sobre
     * el CASO. No cambia certificados, correos ni portal.
     *
     * @return array{decision_id:int,caso_estado:string}
     *
     * @throws No
     */
    public function revocar(int $decisionId, int $actorId, string $motivo): array
    {
        $this->actor($actorId);
        $motivo = $this->motivo($motivo);
        $casoId = (int) DB::table('cf_decisiones_identidad')->where('id', $decisionId)->value('conciliacion_id');
        if ($casoId === 0) {
            throw new No(No::TIPO_NO_ADMITIDO, 'La decisión no existe.');
        }

        return DB::transaction(function () use ($decisionId, $actorId, $motivo, $casoId) {
            $caso = Conciliacion::query()->whereKey($casoId)->lockForUpdate()->firstOrFail();
            $d = DecisionIdentidad::query()->whereKey($decisionId)->lockForUpdate()->firstOrFail();
            if (! $d->esVigente()) {
                throw new No(No::DECISION_YA_REVOCADA, 'Esta decisión ya fue revocada.');
            }
            $ahora = now();
            $d->update(['estado' => DecisionIdentidad::REVOCADA, 'revocada_por' => $actorId, 'revocada_at' => $ahora, 'motivo_revocacion' => $motivo, 'vigente_clave' => null, 'vigente_caso_clave' => null]);
            DecisionIdentidadGrupo::query()->where('decision_id', $d->id)->update(['vigente_tipo' => null]);
            DecisionIdentidadCorreo::query()->where('decision_id', $d->id)->update(['vigente' => null]);
            // 10B-3C-3: la solicitud/aprobación masiva de esta decisión queda `revocada` (historia, no se borra).
            app(AutorizacionMasiva::class)->cerrarAlRevocarDecision($caso, $d, $actorId, $motivo);

            $estadoAnterior = (string) $caso->estado;
            if ($d->efecto_caso === Conciliacion::REQUIERE_SOPORTE && $caso->estado === Conciliacion::REQUIERE_SOPORTE
                && ! DecisionIdentidad::query()->where('conciliacion_id', $caso->id)->where('estado', DecisionIdentidad::VIGENTE)->where('efecto_caso', Conciliacion::REQUIERE_SOPORTE)->lockForUpdate()->exists()) {
                $caso->update(['estado' => Conciliacion::ABIERTO]);
            }
            ConciliacionEvento::create([
                'conciliacion_id' => $caso->id, 'accion' => self::ACCION_REVOCADA, 'estado_anterior' => $estadoAnterior, 'estado_nuevo' => (string) $caso->estado, 'motivo' => $motivo, 'actor_id' => $actorId,
                'evidencia' => ['decision_id' => (int) $d->id, 'tipo' => $d->tipo, 'efecto_caso_deshecho' => $d->efecto_caso !== null && $estadoAnterior !== $caso->estado, 'efecto_portal' => false],
            ]);
            $this->movimiento($actorId, self::ACCION_REVOCADA, (int) $caso->id, (int) $d->id);
            // 10B-3B-2: si esta decisión sustentaba un acceso aplicado y no queda otra vía equivalente, el caso se reabre (auditado).
            app(AplicacionIdentidad::class)->revisarTrasRevocar($caso, (int) $d->id, $actorId);

            return ['decision_id' => (int) $d->id, 'caso_estado' => (string) $caso->estado];
        });
    }

    // ── Consultas (índices preparados para 10B-3B; el portal todavía NO las usa) ──────────────────────

    /** @return Collection<int,object> decisiones VIGENTES de un documento (por `documento_hash`). */
    public static function vigentesDeDocumento(string $documentoHash)
    {
        return DB::table('cf_decisiones_identidad')->where('documento_hash', $documentoHash)->where('estado', DecisionIdentidad::VIGENTE)->orderBy('id')->get();
    }

    /** @return Collection<int,object> decisiones vigentes que abarcan un grupo. */
    public static function vigentesDeGrupo(string $documentoHash, string $grupoHash)
    {
        return DB::table('cf_decisiones_identidad_grupos as g')->join('cf_decisiones_identidad as d', 'd.id', '=', 'g.decision_id')
            ->where('g.documento_hash', $documentoHash)->where('g.grupo_hash', $grupoHash)->whereNotNull('g.vigente_tipo')->get(['d.id', 'd.tipo']);
    }

    /** Grupo al que un correo (HMAC) está autorizado hoy en ese documento, o null. */
    public static function grupoDeCorreo(string $documentoHash, string $correoHmac): ?string
    {
        $g = DB::table('cf_decisiones_identidad_correos')->where('documento_hash', $documentoHash)->where('correo_hmac', $correoHmac)->where('vigente', 1)->value('grupo_hash');

        return $g === null ? null : (string) $g;
    }

    /**
     * Decisiones de un caso para la pantalla (sin datos personales: refs de grupo, máscaras de correo).
     *
     * @return list<array<string,mixed>>
     */
    public static function delCaso(int $casoId): array
    {
        $decisiones = DecisionIdentidad::query()->where('conciliacion_id', $casoId)->orderByDesc('id')->get();
        $grupos = DB::table('cf_decisiones_identidad_grupos')->whereIn('decision_id', $decisiones->pluck('id'))->get(['decision_id', 'grupo_hash'])->groupBy('decision_id');
        $correos = DB::table('cf_decisiones_identidad_correos')->whereIn('decision_id', $decisiones->pluck('id'))->get()->keyBy('decision_id');
        $aprobaciones = AutorizacionMasiva::paraPantalla($decisiones->pluck('id')->map(fn ($i) => (int) $i)->all(), Auth::id() === null ? null : (int) Auth::id());

        return $decisiones->map(fn (DecisionIdentidad $d) => ['aprobacion' => $aprobaciones[$d->id] ?? null] + [
            'id' => (int) $d->id, 'tipo' => $d->tipo, 'estado' => $d->estado, 'motivo' => $d->motivo, 'evidencia' => $d->evidencia,
            'grupos' => ($grupos[$d->id] ?? collect())->map(fn ($g) => substr($g->grupo_hash, 0, 10))->values()->all(),
            'correo' => isset($correos[$d->id]) ? ['mascara' => $correos[$d->id]->correo_mascara, 'grupo' => substr($correos[$d->id]->grupo_hash, 0, 10)] : null,
            'certificados_afectados' => (int) $d->certificados_afectados, 'declaro_evidencia_externa' => (bool) $d->declaro_evidencia_externa, 'confirmo_alcance_masivo' => (bool) $d->confirmo_alcance_masivo,
            'creada_at' => $d->created_at?->toDateTimeString(), 'revocada_at' => $d->revocada_at?->toDateTimeString(), 'motivo_revocacion' => $d->motivo_revocacion,
        ])->all();
    }

    // ── Validación ───────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $ev
     * @param  array<string,mixed>  $p
     * @return array{grupos:list<string>,grupo:?string,correo_hmac:?string,correo_mascara:?string,certificados:int}
     *
     * @throws No
     */
    private function validar(string $tipo, Conciliacion $caso, array $ev, array $p, ?string $evidencia): array
    {
        $vacio = ['grupos' => [], 'grupo' => null, 'correo_hmac' => null, 'correo_mascara' => null, 'certificados' => (int) $ev['certificados'], 'masivo' => false, 'fuente' => null];
        $disponibles = array_keys($ev['por_hash']);

        if ($ev['invalido'] && ! in_array($tipo, DecisionIdentidad::TERMINALES, true)) {
            throw new No(No::TIPO_NO_ADMITIDO, 'Un caso con documento inválido solo admite «requiere soporte» o «no resoluble»: no se puede inferir el documento.');
        }
        // Casos de GRUPOS SIN VÍA (10B-3C-1): solo se recupera un grupo con UNA autorización de correo; «misma persona» / «personas distintas» no hacen falta y no se
        // admiten (el backend lo valida aunque la pantalla ya no las ofrezca). Los casos que requieren evidencia externa o no tienen correo solo admiten soporte.
        $sinVia = GruposSinVia::esMotivo((string) $caso->motivo_origen);
        if ($sinVia) {
            $permitidos = (string) $caso->motivo_origen === GruposSinVia::MOTIVO_COMPARTIDO ? [DecisionIdentidad::CORREO_AUTORIZADO, ...DecisionIdentidad::TERMINALES] : DecisionIdentidad::TERMINALES;
            if (! in_array($tipo, $permitidos, true)) {
                throw new No(No::TIPO_NO_ADMITIDO, 'Este caso solo admite '.($permitidos === DecisionIdentidad::TERMINALES ? 'marcarlo como soporte o no resoluble (requiere evidencia externa).' : 'autorizar el correo compartido para el grupo, o marcarlo como soporte o no resoluble.'));
            }
        }
        if (in_array($tipo, DecisionIdentidad::TERMINALES, true)) {
            return $vacio;
        }

        if ($tipo === DecisionIdentidad::CORREO_AUTORIZADO) {
            $grupo = (string) ($p['grupo'] ?? '');
            $hmac = (string) ($p['correo_hmac'] ?? '');
            if (! in_array($grupo, $disponibles, true)) {
                throw new No(No::GRUPO_NO_PERTENECE, 'Ese grupo no pertenece a este caso.');
            }
            $correo = collect($ev['por_hash'][$grupo]['correos'])->firstWhere('hmac', $hmac);
            if ($correo === null) {
                throw new No(No::CORREO_NO_PERTENECE, 'Ese correo no aparece, en el histórico, en el grupo elegido.');
            }
            if ($sinVia) {
                // El grupo debe ser uno de los grupos SIN VÍA de este caso y el correo debe estar REALMENTE compartido con otro registro histórico.
                if (! in_array($grupo, app(GruposSinVia::class)->objetivosDe($caso), true)) {
                    throw new No(No::GRUPO_NO_PERTENECE, 'Ese grupo no es uno de los grupos sin vía de este caso.');
                }
                if (! $correo['compartido']) {
                    throw new No(No::CORREO_NO_PERTENECE, 'Ese correo no está compartido con otro registro histórico.');
                }
            }
            $this->exigirEvidencia($evidencia);
            if ($correo['compartido'] && ! ($p['reforzada'] ?? false)) {
                throw new No(No::CONFIRMACION_REQUERIDA, 'Este correo aparece en más de un grupo histórico: se requiere la confirmación reforzada.');
            }
            $n = $ev['por_hash'][$grupo]['certificados'];
            $this->exigirMasivo($n, $p);
            $masivo = ModeloAcotado::masivo($n);
            $fuente = null;
            if ($masivo) {
                // DOBLE CONTROL (10B-3C-3): evidencia EXTERNA declarada, su fuente y la confirmación masiva (ya exigida arriba). La segunda aprobación la da otro admin.
                if (! ($p['evidencia_externa'] ?? false)) {
                    throw new No(No::EVIDENCIA_REQUERIDA, 'Una autorización masiva exige declarar evidencia externa explícita.');
                }
                $fuente = (string) ($p['fuente_evidencia'] ?? '');
                if (! in_array($fuente, DecisionIdentidadAprobacion::FUENTES, true)) {
                    throw new No(No::FUENTE_EVIDENCIA_REQUERIDA, 'Indica la fuente de la evidencia externa.');
                }
            }

            return ['grupos' => [], 'grupo' => $grupo, 'correo_hmac' => $hmac, 'correo_mascara' => $correo['mascara'], 'certificados' => $n, 'masivo' => $masivo, 'fuente' => $fuente];
        }

        // misma_persona / personas_distintas: un conjunto explícito de ≥ 2 grupos del documento.
        $grupos = array_values(array_unique(array_map('strval', (array) ($p['grupos'] ?? []))));
        if (count($grupos) < 2) {
            throw new No(No::VALOR_NO_VALIDO, 'Elige al menos dos grupos.');
        }
        if (array_diff($grupos, $disponibles) !== []) {
            throw new No(No::GRUPO_NO_PERTENECE, 'Alguno de los grupos no pertenece a este caso.');
        }
        sort($grupos);
        if (! ($p['confirmo'] ?? false)) {
            throw new No(No::CONFIRMACION_REQUERIDA, 'Debes confirmar la decisión.');
        }
        $n = (int) collect($ev['por_hash'])->only($grupos)->sum('certificados');
        if ($tipo === DecisionIdentidad::MISMA_PERSONA) {
            $this->exigirEvidencia($evidencia);
            $r = EvidenciaIdentidad::riesgoEntre($ev, $grupos);
            // Mismo evento o nombres realmente distintos: la similitud NO basta; hace falta evidencia externa declarada de forma explícita.
            if (($r['mismo_evento'] || $r['nombres_distintos']) && ! ($p['evidencia_externa'] ?? false)) {
                throw new No(No::EVIDENCIA_REQUERIDA, 'Estos grupos aparecen en el mismo evento o tienen nombres realmente distintos: declara explícitamente que existe evidencia externa suficiente.');
            }
            $this->exigirMasivo($n, $p);
        }

        return ['grupos' => $grupos, 'grupo' => null, 'correo_hmac' => null, 'correo_mascara' => null, 'certificados' => $n, 'masivo' => false, 'fuente' => null];
    }

    /**
     * Modelo 1 acotado (10B-3B-1): qué correos podrían abrir el scope SIN una autorización de correo adicional, y si esta misma persona exigirá una. Solo HMAC.
     *
     * @param  array<string,mixed>  $ev
     * @param  array{grupos:list<string>,certificados:int}  $d
     * @return array{correos_admisibles:list<string>,requiere_correo_autorizado:bool}
     */
    private function admisibilidad(array $ev, array $d): array
    {
        $porGrupo = array_map(fn ($g) => array_column($g['correos'], 'hmac'), $ev['por_hash']);
        $candidatos = ModeloAcotado::candidatos($porGrupo, $d['grupos']);

        return ['correos_admisibles' => $candidatos, 'requiere_correo_autorizado' => count($candidatos) !== 1 || ModeloAcotado::masivo($d['certificados'])];
    }

    /** @throws No */
    private function exigirEvidencia(?string $evidencia): void
    {
        if ($evidencia === null) {
            throw new No(No::EVIDENCIA_REQUERIDA, 'Describe la evidencia (entre '.self::EVIDENCIA_MIN.' y '.self::EVIDENCIA_MAX.' caracteres).');
        }
    }

    /** @throws No */
    private function exigirMasivo(int $certificados, array $p): void
    {
        if ($certificados >= EvidenciaIdentidad::UMBRAL_MASIVO && ! ($p['alcance_masivo'] ?? false)) {
            throw new No(No::CONFIRMACION_REQUERIDA, 'Esta decisión podría afectar el acceso a cientos de certificados: confirma que entiendes el alcance.');
        }
    }

    /**
     * @param  array{grupos:list<string>,grupo:?string,correo_hmac:?string}  $d
     * @param  array{grupos:array<int,list<string>>,correos:array<int,string>}  $detalle
     *
     * @throws No
     */
    private function exigirCompatible(string $tipo, array $d, $vigentes, array $detalle, int $casoId): void
    {
        $conflicto = fn (string $m) => new No(No::DECISION_CONFLICTIVA, $m);
        $terminal = in_array($tipo, DecisionIdentidad::TERMINALES, true);
        // 10B-3C-4: las terminales son POR CASO (los casos de grupo sin vía de un mismo documento son independientes entre sí y del caso base).
        $delCaso = $vigentes->filter(fn ($v) => (int) $v->conciliacion_id === $casoId);
        $hayTerminal = $delCaso->contains(fn ($v) => in_array($v->tipo, DecisionIdentidad::TERMINALES, true));
        $haySustantiva = $delCaso->contains(fn ($v) => ! in_array($v->tipo, DecisionIdentidad::TERMINALES, true));

        if ($terminal && ($hayTerminal || $haySustantiva)) {
            throw $conflicto('Este caso ya tiene decisiones vigentes: revócalas antes de marcarlo como soporte o no resoluble.');
        }
        if (! $terminal && $hayTerminal) {
            throw $conflicto('Este caso está marcado como soporte o no resoluble: revoca esa decisión antes de decidir sobre los grupos.');
        }
        foreach ($vigentes as $v) {
            $otros = $detalle['grupos'][$v->id] ?? [];
            if ($tipo === DecisionIdentidad::MISMA_PERSONA && $v->tipo === DecisionIdentidad::MISMA_PERSONA && array_intersect($d['grupos'], $otros) !== []) {
                throw $conflicto('Alguno de esos grupos ya pertenece a otro alcance de «misma persona».');
            }
            if ($tipo === DecisionIdentidad::PERSONAS_DISTINTAS && $v->tipo === DecisionIdentidad::PERSONAS_DISTINTAS && array_intersect($d['grupos'], $otros) !== []) {
                throw $conflicto('Alguno de esos grupos ya pertenece a otra decisión de «personas distintas».');
            }
            $contradice = ($tipo === DecisionIdentidad::MISMA_PERSONA && $v->tipo === DecisionIdentidad::PERSONAS_DISTINTAS) || ($tipo === DecisionIdentidad::PERSONAS_DISTINTAS && $v->tipo === DecisionIdentidad::MISMA_PERSONA);
            if ($contradice && count(array_intersect($d['grupos'], $otros)) >= 2) {
                throw $conflicto('Esta decisión contradice una vigente: afirma y niega que los mismos grupos sean la misma persona.');
            }
            if ($tipo === DecisionIdentidad::CORREO_AUTORIZADO && $v->tipo === DecisionIdentidad::CORREO_AUTORIZADO && ($detalle['correos'][$v->id] ?? null) === $d['correo_hmac']) {
                throw $conflicto('Ese correo ya está autorizado para otro grupo.');
            }
        }
    }

    /** @return array{grupos:array<int,list<string>>,correos:array<int,string>} */
    private function detalle($vigentes): array
    {
        $ids = $vigentes->pluck('id');

        return [
            'grupos' => DB::table('cf_decisiones_identidad_grupos')->whereIn('decision_id', $ids)->lockForUpdate()->get(['decision_id', 'grupo_hash'])->groupBy('decision_id')->map(fn ($g) => $g->pluck('grupo_hash')->all())->all(),
            'correos' => DB::table('cf_decisiones_identidad_correos')->whereIn('decision_id', $ids)->lockForUpdate()->pluck('correo_hmac', 'decision_id')->all(),
        ];
    }

    /** @param  list<string>  $grupos */
    private function clave(int $casoId, string $tipo, array $grupos, ?string $correoHmac, ?string $grupo): string
    {
        return hash('sha256', json_encode([$casoId, $tipo, $grupos, $correoHmac, $grupo], JSON_THROW_ON_ERROR));
    }

    // ── Actor, motivo, evidencia ─────────────────────────────────────────────

    /** @throws No */
    private function actor(int $actorId): void
    {
        $u = Usuario::query()->find($actorId);
        if ($u === null || ! in_array($u->rol, self::ROLES, true)) {
            throw new No(No::ACTOR_NO_AUTORIZADO, 'Solo un administrador puede registrar decisiones de identidad.');
        }
    }

    /** @throws No */
    private function motivo(string $motivo): string
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < ResolucionPlantillas::MOTIVO_MIN || mb_strlen($motivo) > ResolucionPlantillas::MOTIVO_MAX) {
            throw new No(No::TIPO_NO_ADMITIDO, 'El motivo debe tener entre '.ResolucionPlantillas::MOTIVO_MIN.' y '.ResolucionPlantillas::MOTIVO_MAX.' caracteres.');
        }

        return $motivo;
    }

    /** @throws No */
    private function evidenciaTexto(?string $texto): ?string
    {
        if ($texto === null || trim($texto) === '') {
            return null;
        }
        $texto = trim($texto);
        if (mb_strlen($texto) < self::EVIDENCIA_MIN || mb_strlen($texto) > self::EVIDENCIA_MAX) {
            throw new No(No::EVIDENCIA_REQUERIDA, 'La evidencia debe tener entre '.self::EVIDENCIA_MIN.' y '.self::EVIDENCIA_MAX.' caracteres.');
        }

        return $texto;
    }

    private function movimiento(int $actorId, string $accion, int $casoId, int $decisionId): void
    {
        // Sin IP ni agente de usuario: solo ids.
        Movimiento::create(['user_id' => $actorId, 'tipo' => 'conciliacion', 'modulo' => 'credential_flow', 'descripcion' => "Conciliación #{$casoId}: {$accion} #{$decisionId}", 'metadata' => ['conciliacion_id' => $casoId, 'decision_id' => $decisionId, 'accion' => $accion]]);
    }
}
