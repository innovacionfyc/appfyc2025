<?php

namespace App\Support;

use App\Models\Estado;
use RuntimeException;

/**
 * Resuelve IDs de la tabla `estados` por su nombre (`tipo_estado`) para no hard-codear IDs.
 * Cachea en memoria durante la request. Nunca modifica datos.
 */
final class EstadoResolver
{
    public const ACTIVO = 'Activo';
    public const BORRADOR = 'Borrador';
    public const ARCHIVADO = 'Archivado';

    /** @var array<string, int> */
    private static array $cache = [];

    public static function id(string $nombre): int
    {
        $clave = mb_strtolower(trim($nombre));

        if (!isset(self::$cache[$clave])) {
            $id = Estado::where('tipo_estado', $nombre)->value('id');

            if (!$id) {
                throw new RuntimeException("No existe el estado '{$nombre}' en la tabla estados. Ejecuta EstadosSeeder o revisa el catálogo.");
            }

            self::$cache[$clave] = (int) $id;
        }

        return self::$cache[$clave];
    }

    public static function activo(): int
    {
        return self::id(self::ACTIVO);
    }

    public static function borrador(): int
    {
        return self::id(self::BORRADOR);
    }

    public static function archivado(): int
    {
        return self::id(self::ARCHIVADO);
    }

    /**
     * IDs de una lista de nombres, en el mismo orden (útil para reglas `in:`).
     *
     * @param  string[] $nombres
     * @return int[]
     */
    public static function ids(array $nombres): array
    {
        return array_map(fn (string $n) => self::id($n), $nombres);
    }

    public static function limpiarCache(): void
    {
        self::$cache = [];
    }
}
