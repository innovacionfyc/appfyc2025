<?php

namespace App\Support\CredentialFlow\Identidad;

use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\ConciliacionEvento;
use App\Models\CredentialFlow\DecisionIdentidad;
use App\Models\CredentialFlow\DecisionIdentidadAprobacion;
use App\Models\Movimiento;
use App\Models\Usuario;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida as No;
use App\Support\CredentialFlow\Conciliaciones\ResolucionPlantillas;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use Illuminate\Support\Facades\DB;

/**
 * AUTORIZACIÓN MASIVA con DOBLE CONTROL (Fase 10B-3C-3). Una `correo_autorizado` cuyo grupo tiene >= `EvidenciaIdentidad::UMBRAL_MASIVO` certificados
 * nace como SOLICITUD (decisión `vigente` + fila `pendiente` en `cf_decisiones_identidad_aprobaciones`) y NO es aplicable —ni OTP, ni sesión, ni cierre del
 * caso— hasta que un SEGUNDO administrador, distinto del solicitante, la aprueba. El resolver exige la aprobación vigente, la evidencia externa, la
 * confirmación masiva y el interruptor `mass_scope_enabled`; el scope resultante es SIEMPRE el grupo autorizado (nunca une la fila suelta).
 *
 * Todo ocurre en UNA transacción bajo el lock del CASO (mismo orden de locks que `DecisionesIdentidad`: caso → decisión → aprobación). Con dos aprobadores a la
 * vez, el lock + el UNIQUE(decision_id) dejan una aprobación efectiva y el otro recibe YA_APROBADA. Revocar la aprobación es hacia adelante: la fila queda
 * `revocada` (historia), el hash del scope cambia (el id de la aprobación forma parte de él) y toda sesión masiva muere en la siguiente petición.
 * Sin datos personales: ids, conteos y la fuente de la evidencia.
 */
final class AutorizacionMasiva
{
    public const ACCION_SOLICITADA = 'identidad_autorizacion_masiva_solicitada';

    public const ACCION_APROBADA = 'identidad_autorizacion_masiva_aprobada';

    public const ACCION_REVOCADA = 'identidad_autorizacion_masiva_revocada';

    public const TEXTO_CONFIRMACION = 'Confirmo que esta autorización podrá habilitar acceso a más de 600 certificados históricos.';

    /**
     * Blast radius (solo conteos) del grupo autorizado: lo que el scope podría habilitar y lo que queda fuera.
     *
     * @return array{certificados:int,logicos:int,eventos:int,descargables:int,fuera_de_alcance:int}
     */
    public static function alcance(string $claveDocumento, string $grupoHash): array
    {
        $d = app(AccesoPortal::class)->cargar($claveDocumento);
        $dentro = $d['filas']->filter(fn ($f) => $d['grupos'][$f->id] === $grupoHash);

        return [
            'certificados' => $dentro->count(),
            'logicos' => $dentro->map(fn ($f) => $f->grupo_duplicado ?? 'id:'.$f->id)->unique()->count(),
            'eventos' => $dentro->pluck('evento_id')->unique()->count(),
            'descargables' => $dentro->filter(fn ($f) => AccesoPortal::habilitaAcceso($f))->count(),
            'fuera_de_alcance' => $d['filas']->count() - $dentro->count(),
        ];
    }

    /**
     * Registra la SOLICITUD (dentro de la transacción de `DecisionesIdentidad::crear`, con el caso ya bloqueado).
     *
     * @param  array{grupo:?string,certificados:int}  $d
     */
    public function registrarSolicitud(Conciliacion $caso, DecisionIdentidad $decision, array $d, string $fuente, string $claveDocumento, int $actorId): DecisionIdentidadAprobacion
    {
        $a = self::alcance($claveDocumento, (string) $d['grupo']);
        $ap = new DecisionIdentidadAprobacion([
            'decision_id' => $decision->id, 'documento_hash' => $decision->documento_hash, 'estado' => DecisionIdentidadAprobacion::PENDIENTE, 'fuente_evidencia' => $fuente,
            'certificados' => $a['certificados'], 'logicos' => $a['logicos'], 'eventos' => $a['eventos'], 'descargables' => $a['descargables'], 'fuera_de_alcance' => $a['fuera_de_alcance'],
            'solicitada_por' => $actorId, 'solicitada_at' => now(),
        ]);
        $ap->save();
        ConciliacionEvento::create([
            'conciliacion_id' => $caso->id, 'accion' => self::ACCION_SOLICITADA, 'estado_anterior' => (string) $caso->estado, 'estado_nuevo' => (string) $caso->estado, 'motivo' => $decision->motivo, 'actor_id' => $actorId,
            'evidencia' => ['decision_id' => (int) $decision->id, 'aprobacion_id' => (int) $ap->id, 'grupo' => $d['grupo'], 'fuente_evidencia' => $fuente, 'evidencia_sha256' => $decision->evidencia_sha256, 'efecto_portal' => false] + $a,
        ]);
        $this->movimiento($actorId, self::ACCION_SOLICITADA, (int) $caso->id, (int) $decision->id);

        return $ap;
    }

    /**
     * SEGUNDA APROBACIÓN. Rechaza (en el backend, no solo en la interfaz) al mismo actor que solicitó, a quien no sea administrador, la falta de confirmación
     * reforzada, una decisión revocada, una aprobación ya dada o revocada y un blast radius que cambió desde la solicitud.
     *
     * @return array{decision_id:int,aprobacion_id:int,caso_estado:string}
     *
     * @throws No
     */
    public function aprobar(int $decisionId, int $actorId, string $motivo, bool $confirmo): array
    {
        $this->actor($actorId);
        $motivo = $this->motivo($motivo);
        if (! $confirmo) {
            throw new No(No::CONFIRMACION_REQUERIDA, 'Debes confirmar de forma reforzada la aprobación de la autorización masiva.');
        }
        $casoId = (int) DB::table('cf_decisiones_identidad')->where('id', $decisionId)->value('conciliacion_id');
        if ($casoId === 0) {
            throw new No(No::TIPO_NO_ADMITIDO, 'La decisión no existe.');
        }

        return DB::transaction(function () use ($decisionId, $actorId, $motivo, $casoId) {
            $caso = Conciliacion::query()->whereKey($casoId)->lockForUpdate()->firstOrFail();
            $d = DecisionIdentidad::query()->whereKey($decisionId)->lockForUpdate()->firstOrFail();
            $ap = DecisionIdentidadAprobacion::query()->where('decision_id', $decisionId)->lockForUpdate()->first();
            if ($ap === null || $d->tipo !== DecisionIdentidad::CORREO_AUTORIZADO) {
                throw new No(No::APROBACION_NO_APLICA, 'Esta decisión no es una autorización masiva que requiera segunda aprobación.');
            }
            if (! $d->esVigente()) {
                throw new No(No::DECISION_YA_REVOCADA, 'Esta decisión ya fue revocada.');
            }
            if ($ap->estado === DecisionIdentidadAprobacion::APROBADA) {
                throw new No(No::YA_APROBADA, 'Esta autorización masiva ya fue aprobada.');
            }
            if ($ap->estado !== DecisionIdentidadAprobacion::PENDIENTE) {
                throw new No(No::APROBACION_NO_APLICA, 'La aprobación de esta autorización ya fue revocada: solicita una nueva.');
            }
            if ((int) $ap->solicitada_por === $actorId) {
                throw new No(No::SEGUNDO_APROBADOR_REQUERIDO, 'La aprobación debe hacerla un administrador distinto de quien la solicitó.');
            }
            if (! in_array($caso->estado, [Conciliacion::ABIERTO, Conciliacion::REQUIERE_SOPORTE], true) && ! ($caso->estado === Conciliacion::RESUELTO && $caso->resolucion === AplicacionIdentidad::RESOLUCION)) {
                throw new No(No::CASO_YA_RESUELTO, 'Este caso ya fue resuelto o descartado.');
            }
            // El aprobador revisa lo que se solicitó: si el blast radius cambió desde entonces, hay que volver a solicitar.
            $clave = (string) DB::table('cf_certificados_legado as k')->join('cf_conciliaciones_certificados as p', 'p.certificado_legado_id', '=', 'k.id')->where('p.conciliacion_id', $caso->id)->orderBy('k.id')->value('k.documento_clave');
            $grupo = (string) DB::table('cf_decisiones_identidad_correos')->where('decision_id', $d->id)->value('grupo_hash');
            $a = self::alcance($clave, $grupo);
            if ($a['certificados'] !== (int) $ap->certificados || $a['logicos'] !== (int) $ap->logicos || $a['eventos'] !== (int) $ap->eventos) {
                throw new No(No::CERTIFICADOS_CAMBIARON, 'Los certificados del grupo cambiaron desde la solicitud: revoca la decisión y vuelve a solicitarla.');
            }

            $ap->update(['estado' => DecisionIdentidadAprobacion::APROBADA, 'aprobada_por' => $actorId, 'aprobada_at' => now(), 'motivo_aprobacion' => $motivo]);
            $anterior = (string) $caso->estado;
            ConciliacionEvento::create([
                'conciliacion_id' => $caso->id, 'accion' => self::ACCION_APROBADA, 'estado_anterior' => $anterior, 'estado_nuevo' => $anterior, 'motivo' => $motivo, 'actor_id' => $actorId,
                'evidencia' => ['decision_id' => (int) $d->id, 'aprobacion_id' => (int) $ap->id, 'solicitada_por' => (int) $ap->solicitada_por, 'aprobada_por' => $actorId, 'grupo' => $grupo] + $a,
            ]);
            $this->movimiento($actorId, self::ACCION_APROBADA, (int) $caso->id, (int) $d->id);
            // Con la aprobación (y los tres interruptores) existe por fin un acceso real: el caso puede cerrarse como `identidad_aplicada`.
            app(AplicacionIdentidad::class)->aplicarSiCorresponde($caso, $actorId);

            return ['decision_id' => (int) $d->id, 'aprobacion_id' => (int) $ap->id, 'caso_estado' => (string) $caso->estado];
        });
    }

    /**
     * Revoca SOLO la segunda aprobación (hacia adelante): la decisión sigue registrada pero deja de ser aplicable; las sesiones mueren y el caso se reabre si
     * ya no tiene otra vía. Nada se borra.
     *
     * @return array{decision_id:int,aprobacion_id:int,caso_estado:string}
     *
     * @throws No
     */
    public function revocarAprobacion(int $decisionId, int $actorId, string $motivo): array
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
            $ap = DecisionIdentidadAprobacion::query()->where('decision_id', $decisionId)->lockForUpdate()->first();
            if ($ap === null || $ap->estado !== DecisionIdentidadAprobacion::APROBADA) {
                throw new No(No::APROBACION_NO_APLICA, 'Esta autorización no tiene una aprobación vigente que revocar.');
            }
            $ap->update(['estado' => DecisionIdentidadAprobacion::REVOCADA, 'revocada_por' => $actorId, 'revocada_at' => now(), 'motivo_revocacion' => $motivo]);
            $anterior = (string) $caso->estado;
            app(AplicacionIdentidad::class)->revisarTrasRevocar($caso, (int) $d->id, $actorId);
            ConciliacionEvento::create([
                'conciliacion_id' => $caso->id, 'accion' => self::ACCION_REVOCADA, 'estado_anterior' => $anterior, 'estado_nuevo' => (string) $caso->estado, 'motivo' => $motivo, 'actor_id' => $actorId,
                'evidencia' => ['decision_id' => (int) $d->id, 'aprobacion_id' => (int) $ap->id, 'revocada_por' => $actorId, 'solo_aprobacion' => true],
            ]);
            $this->movimiento($actorId, self::ACCION_REVOCADA, (int) $caso->id, (int) $d->id);

            return ['decision_id' => (int) $d->id, 'aprobacion_id' => (int) $ap->id, 'caso_estado' => (string) $caso->estado];
        });
    }

    /**
     * Al revocar la DECISIÓN completa (3A): su solicitud/aprobación queda `revocada` (historia) en la misma transacción. Si estaba aprobada se audita con el
     * evento de revocación; si estaba pendiente, solo cambia de estado (el evento de revocación de la decisión ya la cubre).
     */
    public function cerrarAlRevocarDecision(Conciliacion $caso, DecisionIdentidad $decision, int $actorId, string $motivo): void
    {
        $ap = DecisionIdentidadAprobacion::query()->where('decision_id', $decision->id)->lockForUpdate()->first();
        if ($ap === null || $ap->estado === DecisionIdentidadAprobacion::REVOCADA) {
            return;
        }
        $estabaAprobada = $ap->estado === DecisionIdentidadAprobacion::APROBADA;
        $ap->update(['estado' => DecisionIdentidadAprobacion::REVOCADA, 'revocada_por' => $actorId, 'revocada_at' => now(), 'motivo_revocacion' => $motivo]);
        if ($estabaAprobada) {
            ConciliacionEvento::create([
                'conciliacion_id' => $caso->id, 'accion' => self::ACCION_REVOCADA, 'estado_anterior' => (string) $caso->estado, 'estado_nuevo' => (string) $caso->estado, 'motivo' => $motivo, 'actor_id' => $actorId,
                'evidencia' => ['decision_id' => (int) $decision->id, 'aprobacion_id' => (int) $ap->id, 'revocada_por' => $actorId, 'solo_aprobacion' => false],
            ]);
        }
    }

    /**
     * Estado de la aprobación de cada decisión para la pantalla (sin datos personales). `puede_aprobar`: el actor actual no es quien solicitó.
     *
     * @param  list<int>  $decisionIds
     * @return array<int,array<string,mixed>>
     */
    public static function paraPantalla(array $decisionIds, ?int $actorId): array
    {
        return DecisionIdentidadAprobacion::query()->whereIn('decision_id', $decisionIds)->get()->mapWithKeys(fn (DecisionIdentidadAprobacion $a) => [(int) $a->decision_id => [
            'id' => (int) $a->id, 'estado' => $a->estado, 'fuente_evidencia' => $a->fuente_evidencia,
            'certificados' => (int) $a->certificados, 'logicos' => (int) $a->logicos, 'eventos' => (int) $a->eventos, 'descargables' => (int) $a->descargables, 'fuera_de_alcance' => (int) $a->fuera_de_alcance,
            'solicitada_por_usted' => $actorId !== null && (int) $a->solicitada_por === $actorId, 'solicitada_at' => $a->solicitada_at?->toDateTimeString(),
            'aprobada_at' => $a->aprobada_at?->toDateTimeString(), 'revocada_at' => $a->revocada_at?->toDateTimeString(), 'motivo_aprobacion' => $a->motivo_aprobacion, 'motivo_revocacion' => $a->motivo_revocacion,
            'puede_aprobar' => $a->estado === DecisionIdentidadAprobacion::PENDIENTE && $actorId !== null && (int) $a->solicitada_por !== $actorId,
            'puede_revocar' => $a->estado === DecisionIdentidadAprobacion::APROBADA,
        ]])->all();
    }

    /** @throws No */
    private function actor(int $actorId): void
    {
        $u = Usuario::query()->find($actorId);
        if ($u === null || ! in_array($u->rol, DecisionesIdentidad::ROLES, true)) {
            throw new No(No::ACTOR_NO_AUTORIZADO, 'Solo un administrador puede aprobar autorizaciones masivas.');
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

    private function movimiento(int $actorId, string $accion, int $casoId, int $decisionId): void
    {
        // Sin IP ni agente de usuario: solo ids.
        Movimiento::create(['user_id' => $actorId, 'tipo' => 'conciliacion', 'modulo' => 'credential_flow', 'descripcion' => "Conciliación #{$casoId}: {$accion} #{$decisionId}", 'metadata' => ['conciliacion_id' => $casoId, 'decision_id' => $decisionId, 'accion' => $accion]]);
    }
}
