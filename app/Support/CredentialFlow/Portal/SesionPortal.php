<?php

namespace App\Support\CredentialFlow\Portal;

use App\Support\CredentialFlow\Identidad\IdentidadFlags;
use App\Support\CredentialFlow\Identidad\ResultadoScope;
use App\Support\CredentialFlow\Identidad\RevalidacionScope;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Sesión PÚBLICA del portal. No es un usuario de Laravel (no usa guards ni el modelo User): solo un contexto mínimo en la sesión del
 * SERVIDOR (la cookie lleva únicamente el id de sesión): documento verificado, `grupo` (HMAC del grupo de nombre conservador; null =
 * documento completo; NUNCA el nombre), momento de autenticación, última actividad y caducidad absoluta. Caduca por inactividad y por
 * tope absoluto. Los datos de «pendiente de código» (documento y correo digitados) viven aparte y nunca dan acceso.
 *
 * SCOPE APROBADO (10B-3B-2): una sesión nacida de una identidad aprobada guarda además, SIEMPRE en el servidor, la lista ordenada de `grupo_hash`, el
 * `scope_hash`, los ids de las decisiones y su `vigente_clave` (sin nombres, correos ni PII). `grupo` lleva en ese caso el propio `scope_hash` (nunca null, que
 * significaría «documento completo»; no coincide con ningún grupo real, así que cualquier lector que lo use como grupo niega todo). En CADA petición se revalida
 * por PK que las decisiones siguen vigentes (`RevalidacionScope`); si algo falla, o el payload está corrupto, o los interruptores se apagaron, la sesión se
 * invalida ENTERA (nunca se reduce el scope en silencio). Una sesión histórica no hace ninguna consulta extra.
 */
final class SesionPortal
{
    public const CLAVE = 'cf_portal.contexto';

    public const PENDIENTE = 'cf_portal.pendiente';

    /** La sesión puede guardar un scope de varios grupos (10B-3B-2). Solo se USA si además los dos interruptores de configuración están encendidos. */
    public const SOPORTA_MULTI_GRUPO = true;

    public const MENSAJE_EXPIRADO = 'Tu acceso ha expirado. Solicita un nuevo código.';

    public static function iniciar(Session $s, string $clave, ?string $grupo = null): void
    {
        self::guardar($s, ['documento' => $clave, 'grupo' => $grupo]);
    }

    /**
     * Abre una sesión con el SCOPE APROBADO ya revalidado por el resolver. Devuelve false (y no abre nada) ante cualquier duda: interruptores apagados, scope no
     * aplicable (masivo ⇒ 3C), representación inválida o decisiones que ya no son válidas.
     */
    public static function iniciarAprobado(Session $s, string $clave, ResultadoScope $scope): bool
    {
        if (! IdentidadFlags::multiScopeHabilitado() || ! $scope->aplicable()) {
            return false;
        }
        $norm = self::normalizar($scope->grupos, $scope->decisionIds, null);
        $claves = $norm === null ? null : RevalidacionScope::claves($clave, $norm['decisiones']);
        if ($norm === null || $claves === null || $scope->scopeHash === null || preg_match('/^[0-9a-f]{64}$/', $scope->scopeHash) !== 1) {
            return false;
        }
        // Scope MASIVO (10B-3C-3): las segundas aprobaciones del resolver deben coincidir con las vigentes AHORA (y el interruptor masivo estar encendido).
        $aprobaciones = RevalidacionScope::aprobaciones($clave, $norm['decisiones']);
        if ($aprobaciones === null || $aprobaciones !== $scope->aprobacionIds) {
            return false;
        }
        self::guardar($s, [
            'documento' => $clave, 'grupo' => $scope->scopeHash, 'grupos' => $norm['grupos'], 'scope_hash' => $scope->scopeHash,
            'decisiones' => $norm['decisiones'], 'decisiones_claves' => $claves, 'aprobaciones' => $aprobaciones,
        ]);

        return true;
    }

    /** @param array<string,mixed> $datos */
    private static function guardar(Session $s, array $datos): void
    {
        // Evita la fijación de sesión: id nuevo al autenticar.
        $s->regenerate();
        $s->forget(self::PENDIENTE);
        $ahora = Carbon::now()->getTimestamp();
        $s->put(self::CLAVE, $datos + [
            'authenticated_at' => $ahora, 'last_activity' => $ahora,
            'absolute_expires_at' => $ahora + (int) config('credential_flow.portal.sesion_maxima_minutos') * 60,
        ]);
    }

    /**
     * @return array{documento:string,grupo:?string,grupos:?list<string>,scope_hash:?string,decisiones:list<int>}|null contexto si la sesión sigue vigente (y renueva la inactividad); null si no hay o caducó
     *
     * `grupos`: null = documento completo (solo el flujo histórico); lista = esos grupos (un grupo histórico es la lista de uno); NUNCA una lista vacía.
     */
    public static function contexto(Session $s): ?array
    {
        $c = $s->get(self::CLAVE);
        if (! is_array($c) || ! isset($c['documento'], $c['authenticated_at'], $c['last_activity'], $c['absolute_expires_at'])) {
            return null;
        }
        $ahora = Carbon::now()->getTimestamp();
        if ($ahora > (int) $c['absolute_expires_at'] || $ahora - (int) $c['last_activity'] > (int) config('credential_flow.portal.sesion_inactividad_minutos') * 60) {
            self::cerrar($s);

            return null;
        }

        $scopeHash = $c['scope_hash'] ?? null;
        if ($scopeHash === null) {
            // Sesión histórica (o anterior a 10B-3B-2): solo el grupo único. Un payload con lista de grupos pero sin scope es corrupto.
            if (array_key_exists('grupos', $c) || array_key_exists('decisiones', $c)) {
                return self::invalidar($s, 'payload_historico_con_scope');
            }
            $resultado = ['documento' => (string) $c['documento'], 'grupo' => $c['grupo'] ?? null, 'grupos' => ($c['grupo'] ?? null) === null ? null : [(string) $c['grupo']], 'scope_hash' => null, 'decisiones' => []];
        } else {
            // Sesión con scope aprobado: interruptores, representación y decisiones vigentes (una consulta por PK).
            if (! IdentidadFlags::multiScopeHabilitado()) {
                return self::invalidar($s, 'interruptores_apagados');
            }
            $norm = is_string($scopeHash) && preg_match('/^[0-9a-f]{64}$/', $scopeHash) === 1 && is_array($c['grupos'] ?? null) && is_array($c['decisiones'] ?? null) && is_array($c['decisiones_claves'] ?? null)
                ? self::normalizar($c['grupos'], $c['decisiones'], $c['decisiones_claves']) : null;
            if ($norm === null || ($c['grupo'] ?? null) !== $scopeHash) {
                return self::invalidar($s, 'payload_corrupto');
            }
            $aprobaciones = $c['aprobaciones'] ?? [];
            if (! is_array($aprobaciones) || array_filter($aprobaciones, fn ($i) => ! is_int($i) || $i < 1) !== []) {
                return self::invalidar($s, 'payload_corrupto');
            }
            if (! RevalidacionScope::vigente((string) $c['documento'], $norm['decisiones'], $norm['claves'], array_values($aprobaciones))) {
                return self::invalidar($s, 'decision_no_vigente');
            }
            $resultado = ['documento' => (string) $c['documento'], 'grupo' => $scopeHash, 'grupos' => $norm['grupos'], 'scope_hash' => $scopeHash, 'decisiones' => $norm['decisiones']];
        }

        $c['last_activity'] = $ahora;
        $s->put(self::CLAVE, $c);

        return $resultado;
    }

    /**
     * Representación canónica: grupos válidos, sin duplicados y ordenados (nunca vacíos); ids de decisión enteros positivos, sin duplicados y ordenados;
     * `$claves` (si se pasa) debe ser una por decisión y se devuelve en el mismo orden que los ids ordenados. Cualquier otra forma ⇒ null (corrupto).
     *
     * @param  array<mixed>|null  $grupos
     * @param  array<mixed>  $ids
     * @param  array<mixed>|null  $claves
     * @return array{grupos:list<string>,decisiones:list<int>,claves:list<string>}|null
     */
    private static function normalizar(?array $grupos, array $ids, ?array $claves): ?array
    {
        if ($grupos === null || $grupos === [] || $ids === []) {
            return null;
        }
        foreach ($grupos as $g) {
            if (! is_string($g) || preg_match('/^[0-9a-f]{64}$/', $g) !== 1) {
                return null;
            }
        }
        foreach ($ids as $i) {
            if (! (is_int($i) || (is_string($i) && ctype_digit($i))) || (int) $i < 1) {
                return null;
            }
        }
        $g = array_values(array_unique($grupos));
        sort($g);
        $porId = [];
        foreach (array_values($ids) as $k => $i) {
            if (isset($porId[(int) $i])) {
                return null;
            }
            $porId[(int) $i] = $claves === null ? '' : ($claves[$k] ?? null);
        }
        if ($claves !== null) {
            if (count($claves) !== count($ids) || in_array(null, $porId, true) || array_filter($porId, fn ($x) => ! is_string($x) || preg_match('/^[0-9a-f]{64}$/', $x) !== 1) !== []) {
                return null;
            }
        }
        ksort($porId);

        return ['grupos' => $g, 'decisiones' => array_keys($porId), 'claves' => array_values($porId)];
    }

    /** Cierra TODA la sesión con el mensaje genérico (sin decir por qué) y deja un log técnico sin datos personales. */
    private static function invalidar(Session $s, string $motivo): null
    {
        Log::notice('Credential Flow: sesión del portal con scope de identidad invalidada.', ['motivo' => $motivo]);
        self::cerrar($s);
        $s->flash('aviso', self::MENSAJE_EXPIRADO);

        return null;
    }

    public static function documento(Session $s): ?string
    {
        return self::contexto($s)['documento'] ?? null;
    }

    public static function cerrar(Session $s): void
    {
        $s->forget([self::CLAVE, self::PENDIENTE]);
        $s->invalidate();
    }
}
