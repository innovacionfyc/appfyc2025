<?php

namespace App\Support\CredentialFlow\Migracion;

use RuntimeException;

/**
 * Configuración de la conexión de LECTURA del staging para los comandos delta (`--staging-bd`). Por defecto hereda TODO de la
 * conexión del proceso (la moderna) y solo cambia la base. Con un usuario de staging explícito usa ese usuario y la contraseña de
 * una variable de entorno efímera del proceso (nunca un argumento, nunca .env): host y puerto se siguen heredando. El destino
 * moderno (`database.default`) no se toca: el delta escribe con él y solo LEE el staging con este usuario.
 */
final class ConexionStaging
{
    public const VARIABLE_PASSWORD = 'STAGING_EV_STAGING_PASSWORD';

    /**
     * @return array<string,mixed>
     *
     * @throws RuntimeException si se pide un usuario de staging y falta la contraseña (antes de conectar)
     */
    public static function configurar(string $stagingBd, ?string $usuario = null): array
    {
        $config = [...config('database.connections.'.config('database.default')), 'database' => $stagingBd];

        $usuario = trim((string) $usuario);
        if ($usuario === '') {
            return $config;
        }

        $password = getenv(self::VARIABLE_PASSWORD);
        if ($password === false || $password === '') {
            throw new RuntimeException('Con --staging-usuario hace falta la variable de entorno '.self::VARIABLE_PASSWORD.' (no vacía). No se conectó a nada.');
        }

        return [...$config, 'username' => $usuario, 'password' => $password];
    }
}
