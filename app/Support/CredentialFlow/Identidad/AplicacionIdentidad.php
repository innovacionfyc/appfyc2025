<?php

namespace App\Support\CredentialFlow\Identidad;

use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\ConciliacionEvento;
use App\Models\CredentialFlow\DecisionIdentidad;
use App\Support\CredentialFlow\Conciliaciones\DetectorGruposSinVia;
use Illuminate\Support\Facades\DB;

/**
 * Cierre y reapertura ADMINISTRATIVA del caso según el acceso efectivo (Fase 10B-3B-2). Registrar una decisión (3A) no cierra el caso; sí lo hace comprobar que
 * existe un scope REALMENTE autenticable: `resuelto` / `identidad_aplicada`. El momento es el de la decisión (comprobada con el resolver bajo las reglas de 3B-2),
 * NO cuando alguien pide un OTP: el estado administrativo no depende de que la persona haya intentado entrar.
 *
 * Solo se cierra si TODO esto se cumple: los dos interruptores están encendidos (la infraestructura 3B-2 está habilitada) y existe al menos un correo del
 * documento para el que el resolver produce un scope aprobado NO masivo (los masivos y el riesgo reforzado se difieren a 10B-3C y no cierran nada).
 * `personas_distintas` sin vía individual sigue en soporte; `requiere_soporte` permanece; `no_resoluble` nunca se convierte en `identidad_aplicada`.
 * Al revocar la decisión que sustentaba el acceso se recalcula: si queda otra vía equivalente, el caso sigue resuelto; si no, se REABRE (auditado). Nada se borra.
 * Se llama dentro de la transacción de 3A, con el caso ya bloqueado.
 */
final class AplicacionIdentidad
{
    public const RESOLUCION = 'identidad_aplicada';

    public const ACCION_APLICADO = 'identidad_acceso_aplicado';

    public const ACCION_REVOCADO = 'identidad_acceso_revocado';

    public function __construct(private readonly ScopeIdentidadAprobado $resolver) {}

    /** Primer scope aprobado y aplicable (no masivo) del caso, o null. Determinista: correos en orden. */
    public function scopeAplicable(Conciliacion $caso): ?ResultadoScope
    {
        $clave = (string) DB::table('cf_certificados_legado as k')->join('cf_conciliaciones_certificados as p', 'p.certificado_legado_id', '=', 'k.id')->where('p.conciliacion_id', $caso->id)->orderBy('k.id')->value('k.documento_clave');
        if ($clave === '') {
            return null;
        }
        $correos = DB::table('cf_correos as r')->join('cf_conciliaciones_certificados as p', 'p.certificado_legado_id', '=', 'r.certificado_legado_id')
            ->where('p.conciliacion_id', $caso->id)->where('r.estado', 'valido')->pluck('r.correo_normalizado')->unique()->sort()->values();
        foreach ($correos as $correo) {
            $r = $this->resolver->resolver($clave, (string) $correo);
            if ($r->aplicable()) {
                return $r;
            }
        }

        return null;
    }

    /**
     * Grupos cubiertos por un scope aprobado y aplicable (unión sobre los correos del caso). Un caso `GRUPO_SIN_VIA_CORREO_COMPARTIDO` está COMPLETO solo cuando
     * TODOS sus grupos objetivo están cubiertos (un documento puede tener más de uno).
     *
     * @return list<string>
     */
    private function gruposCubiertos(Conciliacion $caso): array
    {
        $clave = (string) DB::table('cf_certificados_legado as k')->join('cf_conciliaciones_certificados as p', 'p.certificado_legado_id', '=', 'k.id')->where('p.conciliacion_id', $caso->id)->orderBy('k.id')->value('k.documento_clave');
        $correos = DB::table('cf_correos as r')->join('cf_conciliaciones_certificados as p', 'p.certificado_legado_id', '=', 'r.certificado_legado_id')->where('p.conciliacion_id', $caso->id)->where('r.estado', 'valido')->pluck('r.correo_normalizado')->unique()->sort()->values();
        $cubiertos = [];
        foreach ($correos as $correo) {
            $r = $this->resolver->resolver($clave, (string) $correo);
            if ($r->aplicable()) {
                $cubiertos = array_merge($cubiertos, $r->grupos);
            }
        }

        return array_values(array_unique($cubiertos));
    }

    /** ¿El caso tiene ya el acceso que le corresponde? Regla base: algún scope aplicable. Grupos sin vía: todos sus grupos objetivo cubiertos. */
    private function completo(Conciliacion $caso): bool
    {
        if ((string) $caso->motivo_origen !== GruposSinVia::MOTIVO_COMPARTIDO) {
            return $this->scopeAplicable($caso) !== null;
        }
        $objetivos = app(GruposSinVia::class)->objetivosDe($caso);

        return $objetivos !== [] && array_diff($objetivos, $this->gruposCubiertos($caso)) === [];
    }

    /** Cierra el caso si ya existe un acceso efectivo. @return bool true si lo cerró en esta llamada */
    public function aplicarSiCorresponde(Conciliacion $caso, int $actorId): bool
    {
        if (! IdentidadFlags::aplicacionHabilitada() || ! in_array($caso->estado, [Conciliacion::ABIERTO, Conciliacion::REQUIERE_SOPORTE], true)) {
            return false;
        }
        $scope = $this->scopeAplicable($caso);
        if ($scope === null || ! $this->completo($caso)) {
            return false;
        }
        $anterior = (string) $caso->estado;
        $caso->update(['estado' => Conciliacion::RESUELTO, 'resolucion' => self::RESOLUCION, 'resuelto_por' => $actorId, 'resuelto_at' => now()]);
        ConciliacionEvento::create([
            'conciliacion_id' => $caso->id, 'accion' => self::ACCION_APLICADO, 'estado_anterior' => $anterior, 'estado_nuevo' => Conciliacion::RESUELTO, 'motivo' => 'La decisión de identidad habilita el acceso al portal.',
            'actor_id' => $actorId, 'evidencia' => ['decisiones' => $scope->decisionIds, 'scope_hash' => $scope->scopeHash, 'grupos' => count($scope->grupos), 'aplicado_por' => $actorId],
        ]);
        // 10B-3C-4: lo que este scope NO cubre (p. ej. la fila suelta del documento masivo) recibe su PROPIO caso visible; no queda escondido tras el caso principal resuelto.
        $clave = (string) DB::table('cf_certificados_legado as k')->join('cf_conciliaciones_certificados as p', 'p.certificado_legado_id', '=', 'k.id')->where('p.conciliacion_id', $caso->id)->orderBy('k.id')->value('k.documento_clave');
        if ($clave !== '') {
            app(DetectorGruposSinVia::class)->ejecutarDocumento($clave);
        }

        return true;
    }

    /** Tras revocar una decisión: si el caso estaba `identidad_aplicada` y no queda otra vía equivalente, lo reabre. @return bool true si lo reabrió */
    public function revisarTrasRevocar(Conciliacion $caso, int $decisionId, int $actorId): bool
    {
        if ($caso->estado !== Conciliacion::RESUELTO || $caso->resolucion !== self::RESOLUCION) {
            return false;
        }
        if ($this->completo($caso)) {
            return false;   // queda otra autorización vigente equivalente: el caso sigue resuelto
        }
        // Semántica real previa: si otra decisión vigente lo había llevado a soporte, vuelve a soporte; si no, a abierto.
        $nuevo = DecisionIdentidad::query()->where('conciliacion_id', $caso->id)->where('estado', DecisionIdentidad::VIGENTE)->where('efecto_caso', Conciliacion::REQUIERE_SOPORTE)->exists()
            ? Conciliacion::REQUIERE_SOPORTE : Conciliacion::ABIERTO;
        $caso->update(['estado' => $nuevo, 'resolucion' => null, 'resuelto_por' => null, 'resuelto_at' => null]);
        ConciliacionEvento::create([
            'conciliacion_id' => $caso->id, 'accion' => self::ACCION_REVOCADO, 'estado_anterior' => Conciliacion::RESUELTO, 'estado_nuevo' => $nuevo, 'motivo' => 'Se revocó la decisión que sustentaba el acceso y no queda otra vía equivalente.',
            'actor_id' => $actorId, 'evidencia' => ['decision_revocada' => $decisionId, 'reabierto' => true],
        ]);

        return true;
    }
}
