<?php

namespace App\Support\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\ConciliacionEvento;
use App\Models\Movimiento;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * EVIDENCIA EXTERNA de un caso especial (Fase 10B-3C-4). Una vía administrativa para REGISTRAR (no aplicar) evidencia que llegó por fuera del sistema. Reutiliza el
 * log append-only de eventos del caso (`cf_conciliaciones_eventos`): SIN schema nuevo y sin borrar historia.
 *
 *  - `evidencia_externa_registrada`: fuente + resumen (10–1000, lo escribe el administrador: no debe llevar datos personales) + SHA-256 y longitud + actor + fecha.
 *  - `evidencia_externa_invalidada`: apunta al evento registrado (estado `invalidada`); la evidencia original se conserva.
 * Registrar evidencia NO cambia el estado del caso, NO concede acceso, NO crea decisiones ni OTP: solo cambia lo que falta (`CasosEspeciales`) y habilita la
 * siguiente decisión administrativa explícita. Estado de cada evidencia, derivado del log: `vigente` | `invalidada`.
 */
final class EvidenciaExterna
{
    public const ACCION_REGISTRADA = 'evidencia_externa_registrada';

    public const ACCION_INVALIDADA = 'evidencia_externa_invalidada';

    public const RESUMEN_MIN = 10;

    public const RESUMEN_MAX = 1000;

    public const ROLES = ['super-admin', 'admin'];

    /** Fuentes admisibles (la última cubre cualquier otra con su resumen). `documento_correcto` respalda un reemplazo manual de documento vacío. */
    public const FUENTES = [
        'propiedad_buzon' => 'Propiedad del buzón de correo',
        'certificacion_organizador' => 'Certificación del organizador del evento',
        'registro_validado' => 'Documento o registro validado',
        'documento_correcto' => 'Documento de identidad correcto aportado',
        'otra' => 'Otra fuente admisible',
    ];

    /**
     * @return array{evento_id:int,sha256:string,longitud:int}
     *
     * @throws ResolucionNoPermitida
     */
    public function registrar(int $casoId, int $actorId, string $fuente, string $resumen): array
    {
        $this->actor($actorId);
        if (! array_key_exists($fuente, self::FUENTES)) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::FUENTE_EVIDENCIA_REQUERIDA, 'Indica la fuente de la evidencia externa.');
        }
        $resumen = trim($resumen);
        if (mb_strlen($resumen) < self::RESUMEN_MIN || mb_strlen($resumen) > self::RESUMEN_MAX) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::EVIDENCIA_REQUERIDA, 'El resumen de la evidencia debe tener entre '.self::RESUMEN_MIN.' y '.self::RESUMEN_MAX.' caracteres.');
        }

        return DB::transaction(function () use ($casoId, $actorId, $fuente, $resumen) {
            $caso = Conciliacion::query()->whereKey($casoId)->lockForUpdate()->firstOrFail();
            if (! in_array($caso->estado, [Conciliacion::ABIERTO, Conciliacion::REQUIERE_SOPORTE], true)) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::CASO_YA_RESUELTO, 'Este caso ya fue resuelto o descartado: no admite más evidencia.');
            }
            if (! app(CasosEspeciales::class)->clasificar($caso)['admite_evidencia']) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'Este caso no admite evidencia externa: se resuelve con las acciones normales.');
            }
            $sha = hash('sha256', $resumen);
            $e = ConciliacionEvento::create([
                'conciliacion_id' => $caso->id, 'accion' => self::ACCION_REGISTRADA, 'estado_anterior' => (string) $caso->estado, 'estado_nuevo' => (string) $caso->estado, 'motivo' => $resumen, 'actor_id' => $actorId,
                'evidencia' => ['fuente' => $fuente, 'sha256' => $sha, 'longitud' => mb_strlen($resumen), 'estado' => 'vigente', 'efecto_portal' => false],
            ]);
            $this->movimiento($actorId, self::ACCION_REGISTRADA, (int) $caso->id);

            return ['evento_id' => (int) $e->id, 'sha256' => $sha, 'longitud' => mb_strlen($resumen)];
        });
    }

    /**
     * Invalida una evidencia registrada (queda en el log, marcada). @return array{evento_id:int}
     *
     * @throws ResolucionNoPermitida
     */
    public function invalidar(int $casoId, int $actorId, int $eventoId, string $motivo): array
    {
        $this->actor($actorId);
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < ResolucionPlantillas::MOTIVO_MIN || mb_strlen($motivo) > ResolucionPlantillas::MOTIVO_MAX) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'El motivo debe tener entre '.ResolucionPlantillas::MOTIVO_MIN.' y '.ResolucionPlantillas::MOTIVO_MAX.' caracteres.');
        }

        return DB::transaction(function () use ($casoId, $actorId, $eventoId, $motivo) {
            $caso = Conciliacion::query()->whereKey($casoId)->lockForUpdate()->firstOrFail();
            $orig = DB::table('cf_conciliaciones_eventos')->where('id', $eventoId)->where('conciliacion_id', $caso->id)->where('accion', self::ACCION_REGISTRADA)->first();
            if ($orig === null) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'Esa evidencia no pertenece a este caso.');
            }
            if (collect(DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso->id)->where('accion', self::ACCION_INVALIDADA)->pluck('evidencia'))->contains(fn ($j) => (int) (json_decode((string) $j, true)['evidencia_id'] ?? 0) === $eventoId)) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::DECISION_YA_REVOCADA, 'Esa evidencia ya fue invalidada.');
            }
            $e = ConciliacionEvento::create([
                'conciliacion_id' => $caso->id, 'accion' => self::ACCION_INVALIDADA, 'estado_anterior' => (string) $caso->estado, 'estado_nuevo' => (string) $caso->estado, 'motivo' => $motivo, 'actor_id' => $actorId,
                'evidencia' => ['evidencia_id' => $eventoId, 'estado' => 'invalidada', 'efecto_portal' => false],
            ]);
            $this->movimiento($actorId, self::ACCION_INVALIDADA, (int) $caso->id);

            return ['evento_id' => (int) $e->id];
        });
    }

    /**
     * Evidencias del caso con su estado derivado, de la más reciente a la más antigua. El resumen lo ve solo el administrador (detalle, no-store).
     *
     * @return list<array<string,mixed>>
     */
    public static function delCaso(int $casoId): array
    {
        $eventos = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $casoId)->whereIn('accion', [self::ACCION_REGISTRADA, self::ACCION_INVALIDADA])->orderBy('id')->get();
        $invalidadas = $eventos->where('accion', self::ACCION_INVALIDADA)->mapWithKeys(fn ($e) => [(int) (json_decode((string) $e->evidencia, true)['evidencia_id'] ?? 0) => $e->created_at]);

        return $eventos->where('accion', self::ACCION_REGISTRADA)->map(function ($e) use ($invalidadas) {
            $ev = json_decode((string) $e->evidencia, true) ?: [];

            return [
                'id' => (int) $e->id, 'fuente' => (string) ($ev['fuente'] ?? ''), 'fuente_etiqueta' => self::FUENTES[$ev['fuente'] ?? ''] ?? '', 'resumen' => (string) $e->motivo, 'longitud' => (int) ($ev['longitud'] ?? 0),
                'sha256' => substr((string) ($ev['sha256'] ?? ''), 0, 12), 'estado' => $invalidadas->has((int) $e->id) ? 'invalidada' : 'vigente', 'fecha' => (string) $e->created_at, 'invalidada_at' => $invalidadas[(int) $e->id] ?? null,
            ];
        })->reverse()->values()->all();
    }

    /** ¿Tiene el caso al menos una evidencia VIGENTE (de la fuente indicada, si se pide)? */
    public static function tieneVigente(int $casoId, ?string $fuente = null): bool
    {
        return collect(self::delCaso($casoId))->contains(fn ($e) => $e['estado'] === 'vigente' && ($fuente === null || $e['fuente'] === $fuente));
    }

    /** @throws ResolucionNoPermitida */
    private function actor(int $actorId): void
    {
        $u = Usuario::query()->find($actorId);
        if ($u === null || ! in_array($u->rol, self::ROLES, true)) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::ACTOR_NO_AUTORIZADO, 'Solo un administrador puede registrar evidencia externa.');
        }
    }

    private function movimiento(int $actorId, string $accion, int $casoId): void
    {
        // Sin IP ni agente de usuario: solo ids.
        Movimiento::create(['user_id' => $actorId, 'tipo' => 'conciliacion', 'modulo' => 'credential_flow', 'descripcion' => "Conciliación #{$casoId}: {$accion}", 'metadata' => ['conciliacion_id' => $casoId, 'accion' => $accion]]);
    }
}
