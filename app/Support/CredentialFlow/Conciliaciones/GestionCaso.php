<?php

namespace App\Support\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\ConciliacionEvento;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Support\Facades\DB;

/**
 * Gestión administrativa de casos que NO se pueden resolver con evidencia (Fase 10B-2B-1): «requiere soporte» y «descartar». Cambian
 * SOLO el caso (estado, evento append-only y Movimiento): ni el certificado, ni su documento, ni su estado histórico. No borran nada.
 *
 * Reglas explícitas (revalidadas bajo `lockForUpdate`; fuera de ellas no hay acción):
 *  - requiere_soporte:
 *      · DIF_NOMBRE con nombres REALMENTE distintos (distintos aun con la normalización conservadora);
 *      · DOC_VACIO con nombre presente;
 *      · DOC_LETRAS cuyo «documento» es solo texto con espacios (letras, sin dígitos): no es un identificador.
 *  - descartado: DOC_VACIO sin nombre, sin correo (ni válido ni inválido), sin código legado, sin descarga y sin encuesta.
 *  Quedan FUERA a propósito los documentales confirmables (alfanumérico, NBSP, separadores, «otro»): se resuelven con la emisión moderna.
 * Reabrir (servicio técnico, sin botón) devuelve el caso a `abierto` con su evento.
 */
final class GestionCaso
{
    public const SOPORTE = 'requiere_soporte';

    public const DESCARTE = 'descartar';

    public const ACCION_SOPORTE = 'conciliacion_requiere_soporte';

    public const ACCION_DESCARTE = 'conciliacion_descartada';

    public const ACCION_REABIERTA = 'conciliacion_reabierta';

    public const RESOLUCION_DESCARTE = 'sin_evidencia_util';

    /**
     * Qué acción de gestión admite el caso (sin escribir). Con `$bloquear`, bajo lock.
     *
     * @return array{accion:?string,categoria:?string,motivo:?string}
     */
    public function evaluar(Conciliacion $caso, bool $bloquear = false): array
    {
        $ninguna = ['accion' => null, 'categoria' => null, 'motivo' => 'Este caso no admite esta acción.'];
        $ids = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->orderBy('certificado_legado_id')->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all();
        $consulta = DB::table('cf_certificados_legado')->whereIn('id', $ids)->orderBy('id');
        $filas = ($bloquear ? $consulta->lockForUpdate() : $consulta)->get(['id', 'nombre_completo', 'documento', 'codigo_legado', 'conciliacion_estado', 'snapshot_legado']);
        if ($filas->isEmpty()) {
            return $ninguna;
        }

        if ($caso->tipo === Conciliacion::TIPO_CONFLICTO_VARIANTES) {
            $et = DetectorConciliaciones::lista((json_decode((string) $filas->first()->snapshot_legado, true) ?: [])['duplicado']['etiquetas'] ?? null);
            if ($et === ['DIF_NOMBRE'] && $filas->count() >= 2 && $filas->every(fn ($f) => $f->conciliacion_estado === CertificadoLegado::CONCILIACION_PENDIENTE)
                && $filas->map(fn ($f) => NombreConservador::normalizar((string) $f->nombre_completo))->unique(null, true)->count() > 1) {
                return ['accion' => self::SOPORTE, 'categoria' => 'nombre_realmente_distinto', 'motivo' => null];
            }

            return $ninguna;
        }

        if ($caso->tipo === Conciliacion::TIPO_REVISION_DOCUMENTO && $filas->count() === 1 && $filas->first()->conciliacion_estado === CertificadoLegado::CONCILIACION_REVISION_DOCUMENTO) {
            $f = $filas->first();
            $detalle = DetectorConciliaciones::lista((json_decode((string) $f->snapshot_legado, true) ?: [])['motivos'] ?? null);
            $esVacio = in_array('DOC_VACIO', $detalle, true) && trim((string) $f->documento) === '';
            $tieneNombre = trim((string) $f->nombre_completo) !== '';
            if ($esVacio && $tieneNombre) {
                return ['accion' => self::SOPORTE, 'categoria' => 'documento_vacio_con_nombre', 'motivo' => null];
            }
            if ($esVacio && ! $tieneNombre && $this->sinEvidencia((int) $f->id, $f)) {
                return ['accion' => self::DESCARTE, 'categoria' => 'documento_vacio_sin_evidencia', 'motivo' => null];
            }
            // DOC_LETRAS: solo texto con espacios (letras, sin dígitos, al menos dos palabras) → no es un identificador.
            $original = trim((string) $f->documento);
            if (in_array('DOC_LETRAS', $detalle, true) && preg_match('/^\p{L}+(?:\s+\p{L}+)+$/u', $original) === 1 && preg_match('/\d/', $original) !== 1) {
                return ['accion' => self::SOPORTE, 'categoria' => 'documento_texto_no_identificador', 'motivo' => null];
            }
        }

        return $ninguna;
    }

    /** @return array{caso_id:int,estado:string,categoria:string} */
    public function marcarRequiereSoporte(int $casoId, int $actorId, string $motivo): array
    {
        return $this->aplicar($casoId, $actorId, $motivo, self::SOPORTE, Conciliacion::REQUIERE_SOPORTE, self::ACCION_SOPORTE, null);
    }

    /** @return array{caso_id:int,estado:string,categoria:string} */
    public function descartar(int $casoId, int $actorId, string $motivo): array
    {
        return $this->aplicar($casoId, $actorId, $motivo, self::DESCARTE, Conciliacion::DESCARTADO, self::ACCION_DESCARTE, self::RESOLUCION_DESCARTE);
    }

    /**
     * Reabre un caso marcado «requiere soporte» o «descartado» (servicio técnico, sin botón): solo si ese fue su ÚLTIMO evento y los certificados
     * no cambiaron.
     *
     * @return array{caso_id:int}
     */
    public function reabrir(int $casoId, int $actorId, string $motivo): array
    {
        $motivo = $this->motivoValido($motivo);

        return DB::transaction(function () use ($casoId, $actorId, $motivo) {
            $caso = Conciliacion::query()->whereKey($casoId)->lockForUpdate()->firstOrFail();
            // La evidencia externa registrada DESPUÉS del soporte (10B-3C-4) no impide reabrir: es justamente lo que lo justifica.
            $ultimo = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso->id)->whereNotIn('accion', [EvidenciaExterna::ACCION_REGISTRADA, EvidenciaExterna::ACCION_INVALIDADA])->orderByDesc('id')->first();
            if (! in_array($caso->estado, [Conciliacion::REQUIERE_SOPORTE, Conciliacion::DESCARTADO], true) || $ultimo === null || ! in_array($ultimo->accion, [self::ACCION_SOPORTE, self::ACCION_DESCARTE], true)) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::NO_REVERSIBLE, 'Este caso no se puede reabrir (no está en soporte o descartado, o ya hay decisiones posteriores).');
            }
            $estadoAnterior = $caso->estado;
            $caso->update(['estado' => Conciliacion::ABIERTO, 'resolucion' => null, 'resuelto_por' => null, 'resuelto_at' => null]);
            ConciliacionEvento::create(['conciliacion_id' => $caso->id, 'accion' => self::ACCION_REABIERTA, 'estado_anterior' => $estadoAnterior, 'estado_nuevo' => Conciliacion::ABIERTO, 'motivo' => $motivo, 'actor_id' => $actorId,
                'evidencia' => ['reabre' => $ultimo->accion]]);
            $this->movimiento($actorId, self::ACCION_REABIERTA, $caso->id);

            return ['caso_id' => (int) $caso->id];
        });
    }

    private function aplicar(int $casoId, int $actorId, string $motivo, string $accionPermitida, string $estadoNuevo, string $accionEvento, ?string $resolucion): array
    {
        $motivo = $this->motivoValido($motivo);

        return DB::transaction(function () use ($casoId, $actorId, $motivo, $accionPermitida, $estadoNuevo, $accionEvento, $resolucion) {
            $caso = Conciliacion::query()->whereKey($casoId)->lockForUpdate()->firstOrFail();
            if ($caso->estado !== Conciliacion::ABIERTO) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::CASO_YA_RESUELTO, 'Este caso ya fue resuelto.');
            }
            $e = $this->evaluar($caso, true);
            if ($e['accion'] !== $accionPermitida) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'Esta acción no corresponde a este caso.');
            }
            $ahora = now();
            $caso->update(['estado' => $estadoNuevo, 'resolucion' => $resolucion, 'resuelto_por' => $resolucion === null ? null : $actorId, 'resuelto_at' => $resolucion === null ? null : $ahora]);
            ConciliacionEvento::create(['conciliacion_id' => $caso->id, 'accion' => $accionEvento, 'estado_anterior' => Conciliacion::ABIERTO, 'estado_nuevo' => $estadoNuevo, 'motivo' => $motivo, 'actor_id' => $actorId,
                'evidencia' => ['categoria' => $e['categoria'], 'certificados' => DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->count(), 'certificados_modificados' => 0]]);
            $this->movimiento($actorId, $accionEvento, $caso->id);

            return ['caso_id' => (int) $caso->id, 'estado' => $estadoNuevo, 'categoria' => (string) $e['categoria']];
        });
    }

    /** Sin nombre, sin correo (ni válido ni inválido), sin código legado, sin descarga y sin encuesta. */
    private function sinEvidencia(int $id, object $f): bool
    {
        $snap = json_decode((string) $f->snapshot_legado, true) ?: [];

        return trim((string) $f->nombre_completo) === '' && $f->codigo_legado === null
            && (int) ($snap['correos_candidatos'] ?? 0) === 0 && ! DB::table('cf_correos')->where('certificado_legado_id', $id)->exists()
            && ! DB::table('cf_descargas')->where('certificado_legado_id', $id)->exists()
            && ! DB::table('cf_encuestas_respuestas')->where('certificado_legado_id', $id)->exists();
    }

    private function motivoValido(string $motivo): string
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < ResolucionPlantillas::MOTIVO_MIN || mb_strlen($motivo) > ResolucionPlantillas::MOTIVO_MAX) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'El motivo debe tener entre '.ResolucionPlantillas::MOTIVO_MIN.' y '.ResolucionPlantillas::MOTIVO_MAX.' caracteres.');
        }

        return $motivo;
    }

    private function movimiento(int $actorId, string $accion, int $casoId): void
    {
        // Sin IP ni agente de usuario: solo ids y conteos, nada personal.
        Movimiento::create(['user_id' => $actorId, 'tipo' => 'conciliacion', 'modulo' => 'credential_flow', 'descripcion' => "Conciliación #{$casoId}: {$accion}", 'metadata' => ['conciliacion_id' => $casoId, 'accion' => $accion]]);
    }
}
