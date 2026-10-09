<?php

namespace App\Support\CredentialFlow\Migracion;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Salvaguardas de la migración histórica (Fase 4): solo bases LOCALES de pruebas como destino y solo bases LOCALES de
 * staging como origen. Nunca la base de desarrollo ni producción. La base destino se elige por proceso con DB_DATABASE
 * (nunca se edita .env), igual que en el staging.
 */
final class GuardiaMigracion
{
    /** Bases destino admitidas: terminan en _test/_testing o contienen «migracion_legado» (p. ej. appfyc2025_migracion_legado_test) o «rehearsal» (Fase 11A). */
    private const DESTINO = '/(_test(ing)?$|migracion_legado|rehearsal)/i';

    private const ORIGEN = '/staging/i';

    public static function exigirDestinoSeguro(?string $conexion = null): void
    {
        self::exigir($conexion, self::DESTINO, 'destino', 'una base local de pruebas de migración (p. ej. appfyc2025_migracion_legado_test)');
    }

    public static function exigirOrigenStaging(?string $conexion = null): void
    {
        // Sin conexión aparte, el staging vive en la misma base que el destino (así corren las pruebas): basta la guardia del destino.
        if ($conexion === null) {
            self::exigirDestinoSeguro();

            return;
        }
        self::exigir($conexion, self::ORIGEN, 'origen', 'una base local de staging (p. ej. appfyc2025_staging_ev)');
    }

    private static function exigir(?string $conexion, string $patron, string $rol, string $esperado): void
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
        if (preg_match($patron, $base) !== 1) {
            throw new RuntimeException("La base de {$rol} «{$base}» no es {$esperado}: no se migra.");
        }
    }
}
