<?php

namespace App\Support\CredentialFlow\StagingEv;

use RuntimeException;

/**
 * Configuración de la conexión ORIGEN del staging (la restauración del dump). Por defecto hereda TODO de la conexión del
 * proceso y solo cambia la base. Con un usuario de origen explícito, usa ese usuario y la contraseña de una variable de
 * entorno efímera del proceso (nunca un argumento, nunca .env): host y puerto se siguen heredando.
 */
final class OrigenConexion
{
    public const VARIABLE_PASSWORD = 'STAGING_EV_ORIGEN_PASSWORD';

    /**
     * @return array<string,mixed>
     *
     * @throws RuntimeException si se pide un usuario de origen y falta la contraseña (antes de conectar)
     */
    public static function configurar(string $origenBd, ?string $usuario = null): array
    {
        $config = [...config('database.connections.'.config('database.default')), 'database' => $origenBd];

        $usuario = trim((string) $usuario);
        if ($usuario === '') {
            return $config;
        }

        $password = getenv(self::VARIABLE_PASSWORD);
        if ($password === false || $password === '') {
            throw new RuntimeException('Con --origen-usuario hace falta la variable de entorno '.self::VARIABLE_PASSWORD.' (no vacía). No se conectó a nada.');
        }

        return [...$config, 'username' => $usuario, 'password' => $password];
    }
}
