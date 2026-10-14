<?php

namespace App\Support\CredentialFlow\Migracion;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Salvaguardas de la migración histórica (Fase 4): solo bases LOCALES de pruebas como destino y solo bases LOCALES de
 * staging como origen. Nunca la base de desarrollo ni producción. La base destino se elige por proceso con DB_DATABASE
 * (nunca se edita .env), igual que en el staging.
 *
 * Excepción de producción (Fase 11E-A1), deliberadamente estrecha y efímera: SOLO la base exacta «appfyc», y solo si
 * coinciden SIMULTÁNEAMENTE (1) la opción `--confirmo-produccion=appfyc` del comando y (2) la variable de PROCESO
 * `CREDENTIAL_FLOW_ALLOW_PRODUCTION_MIGRATION=appfyc` (se lee con getenv(): nunca config()/env(), que dependen de config:cache),
 * en CLI y con el portal apagado. Ningún valor genérico («1», «true»), parcial ni comodín la activa. La variable no puede
 * figurar en `.env`. Sin la doble confirmación el comportamiento es idéntico al anterior. Solo la usan
 * `credential-flow:legado:migrar` y `credential-flow:encuestas:migrar`.
 */
final class GuardiaMigracion
{
    /** Bases destino admitidas: terminan en _test/_testing o contienen «migracion_legado» (p. ej. appfyc2025_migracion_legado_test) o «rehearsal» (Fase 11A). */
    private const DESTINO = '/(_test(ing)?$|migracion_legado|rehearsal)/i';

    private const ORIGEN = '/staging/i';

    /** @var array<string,bool> autorizaciones ya registradas en este proceso */
    private static array $registradas = [];

    /** Única base productiva que admite la excepción (coincidencia exacta). */
    public const BASE_PRODUCCION = 'appfyc';

    /** Opción CLI (valor exacto: el nombre de la base). */
    public const OPCION_PRODUCCION = 'confirmo-produccion';

    /** Variable de PROCESO (valor exacto: el nombre de la base). Jamás en .env ni en la configuración del servidor. */
    public const VARIABLE_PRODUCCION = 'CREDENTIAL_FLOW_ALLOW_PRODUCTION_MIGRATION';

    /**
     * Lista CERRADA de comandos que pueden obtener la excepción de producción: la migración base y la migración DELTA (Fase 11F-B)
     * con sus rollbacks técnicos. Cualquier otro nombre (incluido vacío) queda bloqueado aunque la doble confirmación sea correcta.
     */
    public const COMANDOS_PRODUCCION = [
        'credential-flow:legado:migrar',
        'credential-flow:encuestas:migrar',
        'credential-flow:legado:delta',
        'credential-flow:encuestas:delta',
        'credential-flow:legado:delta-rollback',
        'credential-flow:encuestas:delta-rollback',
    ];

    public static function exigirDestinoSeguro(?string $conexion = null, ?string $confirmoProduccion = null, string $comando = ''): void
    {
        self::exigir($conexion, self::DESTINO, 'destino', 'una base local de pruebas de migración (p. ej. appfyc2025_migracion_legado_test)', $confirmoProduccion, $comando);
    }

    public static function exigirOrigenStaging(?string $conexion = null, ?string $confirmoProduccion = null, string $comando = ''): void
    {
        // Sin conexión aparte, el staging vive en la misma base que el destino (así corren las pruebas): basta la guardia del destino.
        if ($conexion === null) {
            self::exigirDestinoSeguro(null, $confirmoProduccion, $comando);

            return;
        }
        self::exigir($conexion, self::ORIGEN, 'origen', 'una base local de staging (p. ej. appfyc2025_staging_ev)');
    }

    private static function exigir(?string $conexion, string $patron, string $rol, string $esperado, ?string $confirmoProduccion = null, string $comando = ''): void
    {
        $c = DB::connection($conexion);
        $driver = $c->getDriverName();
        $base = (string) $c->getDatabaseName();

        // Las pruebas automáticas corren en SQLite en memoria: se admite solo así.
        if ($driver === 'sqlite') {
            if ($base === ':memory:') {
                return;
            }
            throw new RuntimeException("La base de {$rol} (SQLite «{$base}») no es una base de pruebas en memoria.");
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Motor no admitido para la base de {$rol}: {$driver}.");
        }

        $host = (string) config('database.connections.'.$c->getName().'.host');
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException("La base de {$rol} no es local (host {$host}): no se migra.");
        }
        if (preg_match($patron, $base) === 1) {
            return;
        }

        // Excepción de producción: solo para el DESTINO, con la doble confirmación exacta (ver la clase).
        if ($rol === 'destino' && self::produccionAutorizada($base, $confirmoProduccion, $comando)) {
            // Una sola entrada por proceso y comando (la guardia puede evaluarse dos veces: destino y origen en la misma base).
            $clave = $base.'|'.$comando;
            if (! isset(self::$registradas[$clave])) {
                self::$registradas[$clave] = true;
                Log::warning('MIGRACION_PRODUCCION_AUTORIZADA', ['base' => $base, 'comando' => $comando, 'timestamp' => now()->toIso8601String()]);
            }

            return;
        }

        throw new RuntimeException(
            "La base de {$rol} «{$base}» no es {$esperado}: no se migra."
            .($rol === 'destino' && $base === self::BASE_PRODUCCION
                ? ' La migración productiva exige --'.self::OPCION_PRODUCCION.'='.self::BASE_PRODUCCION.', la variable de proceso '.self::VARIABLE_PRODUCCION.'='.self::BASE_PRODUCCION.', CLI y el portal apagado.'
                : '')
        );
    }

    /** Solo para pruebas: olvida las autorizaciones registradas en este proceso. */
    public static function olvidarRegistroDeAutorizaciones(): void
    {
        self::$registradas = [];
    }

    /** Todas las condiciones deben cumplirse a la vez; cualquier otra combinación mantiene el bloqueo. */
    private static function produccionAutorizada(string $base, ?string $confirmoProduccion, string $comando = ''): bool
    {
        if ($base !== self::BASE_PRODUCCION || $confirmoProduccion !== self::BASE_PRODUCCION || ! in_array($comando, self::COMANDOS_PRODUCCION, true)) {
            return false;
        }
        // Variable de PROCESO leída directamente (no config()/env(): con config:cache no se releerían).
        if (getenv(self::VARIABLE_PRODUCCION) !== self::BASE_PRODUCCION) {
            return false;
        }
        if (! in_array(PHP_SAPI, ['cli', 'phpdbg'], true) || ! app()->runningInConsole()) {
            return false;
        }
        if (self::dotenvDeclaraLaVariable()) {
            return false;
        }

        // Con el portal encendido nunca se migra producción (solo se acepta un false estricto).
        return config('credential_flow.portal_enabled', false) === false;
    }

    /** La confirmación es efímera: si alguien la dejó en .env se rechaza (Laravel la expondría por getenv()). */
    private static function dotenvDeclaraLaVariable(): bool
    {
        $ruta = base_path('.env');
        if (! is_file($ruta) || ! is_readable($ruta)) {
            return false;
        }
        $contenido = (string) file_get_contents($ruta);

        return preg_match('/^\s*(export\s+)?'.preg_quote(self::VARIABLE_PRODUCCION, '/').'\s*=/m', $contenido) === 1;
    }
}
