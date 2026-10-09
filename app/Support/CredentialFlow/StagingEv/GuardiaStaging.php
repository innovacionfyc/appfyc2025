<?php

namespace App\Support\CredentialFlow\StagingEv;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Salvaguardas de los comandos de staging: el histórico con datos personales solo se carga en una base LOCAL de staging
 * (o de pruebas), nunca en la base de desarrollo habitual ni en un servidor remoto.
 */
final class GuardiaStaging
{
    /** @throws RuntimeException si la conexión por defecto no es una base local de staging o de pruebas */
    public static function exigirDestinoLocal(): void
    {
        $conexion = DB::connection();
        $driver = $conexion->getDriverName();
        $base = (string) $conexion->getDatabaseName();

        if ($driver === 'sqlite' && ($base === ':memory:' || $base === '')) {
            return;
        }
        $host = (string) ($conexion->getConfig('host') ?? '');
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('El staging solo se puede usar en una base de datos local (127.0.0.1).');
        }
        if (preg_match('/(staging|_test(ing)?$)/i', $base) !== 1) {
            throw new RuntimeException("La base «{$base}» no parece de staging: su nombre debe contener «staging» o terminar en «_test». No se hizo nada.");
        }
    }

    /**
     * Nombre de la base local que contiene la restauración del dump (solo lectura). Debe distinguirse claramente de la de
     * destino y de la de desarrollo.
     */
    public static function exigirOrigenLocal(string $base): void
    {
        $destino = (string) DB::connection()->getDatabaseName();
        $config = config('database.connections.'.config('database.default'));
        if ($base === $destino || $base === ($config['database'] ?? null) || preg_match('/(snapshot|origen)/i', $base) !== 1) {
            throw new RuntimeException('La base de origen debe llamarse con «snapshot» u «origen» y ser distinta de la de destino.');
        }
    }
}
