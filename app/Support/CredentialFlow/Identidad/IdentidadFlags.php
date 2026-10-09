<?php

namespace App\Support\CredentialFlow\Identidad;

use App\Support\CredentialFlow\Portal\SesionPortal;

/**
 * Interruptores de la identidad aprobada (10B-3B). Ambos APAGADOS por defecto (config/credential_flow.php).
 *
 *  - `decisionesHabilitadas`: el portal consulta las decisiones (resolver + OTP con scope congelado). Apagado ⇒ el sistema se comporta EXACTAMENTE como antes.
 *  - `multiScopeHabilitado`: la sesión puede guardar un scope de varios grupos. DOBLE GUARDIA: además del interruptor exige que `SesionPortal` lo soporte
 *    (constante de código, true desde 10B-3B-2). Sin el soporte en código, activar el interruptor por error no abriría ninguna sesión multi-grupo.
 */
final class IdentidadFlags
{
    public static function decisionesHabilitadas(): bool
    {
        return config('credential_flow.identidad.decisiones_enabled', false) === true;
    }

    /**
     * La sesión puede aplicar un scope de varios grupos. Triple condición: el interruptor de sesión, el de decisiones (el de sesión SOLO no tiene efecto útil)
     * y el soporte real en código de `SesionPortal`. Es la única puerta para que una decisión conceda acceso al portal.
     */
    public static function multiScopeHabilitado(): bool
    {
        return SesionPortal::SOPORTA_MULTI_GRUPO === true
            && config('credential_flow.identidad.multi_scope_enabled', false) === true
            && config('credential_flow.identidad.decisiones_enabled', false) === true;
    }

    /**
     * Acceso MASIVO (>= 100 certificados, 10B-3C-3): exige los TRES interruptores. Control operativo permanente e independiente: apagarlo mata, en la siguiente
     * petición, toda sesión masiva y deja de emitir OTP masivos, sin tocar decisiones ni aprobaciones.
     */
    public static function masaHabilitada(): bool
    {
        return config('credential_flow.identidad.mass_scope_enabled', false) === true && self::multiScopeHabilitado();
    }

    /** Alias semántico: «las decisiones de identidad pueden conceder acceso real». */
    public static function aplicacionHabilitada(): bool
    {
        return self::multiScopeHabilitado();
    }
}
