<?php

namespace App\Support\CredentialFlow\Emisiones;

use Closure;

/**
 * Comprobación de espacio libre ANTES de emitir en masa o de armar un ZIP. Mide el disco real en tiempo de
 * ejecución (nada de constantes) y deja un margen de seguridad. Los tests pueden simular el espacio libre
 * con `simular()` sin llenar ningún disco.
 */
final class EspacioDisco
{
    /** Margen proporcional sobre lo estimado. */
    public const FACTOR_MARGEN = 1.25;

    /** Reserva mínima que siempre debe quedar libre. */
    public const RESERVA_BYTES = 100 * 1024 * 1024;

    /** Sobrecosto estimado por PDF además del PDF base (fuentes subseteadas, estructura). */
    public const SOBRECOSTO_PDF_BYTES = 150 * 1024;

    private static ?Closure $simulador = null;

    /** Solo para tests: fija el espacio libre que se reportará (null restaura el valor real). */
    public static function simular(?int $bytesLibres): void
    {
        self::$simulador = $bytesLibres === null ? null : fn () => (float) $bytesLibres;
    }

    public static function libre(string $ruta): ?float
    {
        if (self::$simulador !== null) {
            return (self::$simulador)($ruta);
        }
        $libre = @disk_free_space($ruta);

        return $libre === false ? null : (float) $libre;
    }

    /** Bytes estimados para `cantidad` emisiones que usan un PDF base de `bytesBase`. */
    public static function estimarEmisiones(int $cantidad, int $bytesBase): int
    {
        return $cantidad * ($bytesBase + self::SOBRECOSTO_PDF_BYTES);
    }

    /** @throws EmisionException ESPACIO_INSUFICIENTE (507 no es de uso común: se responde 409) */
    public static function exigir(int $bytesNecesarios, string $ruta): void
    {
        $libre = self::libre($ruta);
        if ($libre === null) {
            return; // no se puede medir: no se bloquea por una medición imposible
        }

        $requerido = (int) ceil($bytesNecesarios * self::FACTOR_MARGEN) + self::RESERVA_BYTES;
        if ($libre < $requerido) {
            throw new EmisionException(
                EmisionException::ESPACIO_INSUFICIENTE,
                'No hay espacio suficiente en el servidor para esta operación. Libera espacio o avisa al equipo técnico.',
                409
            );
        }
    }
}
