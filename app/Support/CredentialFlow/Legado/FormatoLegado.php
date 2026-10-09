<?php

namespace App\Support\CredentialFlow\Legado;

/**
 * Cómo imprimía el sistema viejo el documento: `$tipo.': '.number_format($documento, 0, ',', '.')` en PHP 7.4.
 *
 * number_format() recibe un float. PHP 7.4 convertía la cadena por su PREFIJO numérico; si no tenía ninguno devolvía NULL
 * (no se imprimía nada). PHP 8 lanza TypeError con una cadena no numérica, así que NO se llama a number_format con la
 * cadena: se extrae el prefijo igual que 7.4 y solo entonces se formatea el float (el formato en sí no cambió).
 *
 * Referencias (verificadas con PHP 7.4.33 real): `73156827`→`73.156.827`, `009876543`→`9.876.543`, `73.156.827`→`73`,
 * `ABC123`→null, `123abc`→`123`, ``→null, ` 123`→`123`, `123 456`→`123`, NBSP inicial→null.
 */
final class FormatoLegado
{
    public static function documento(?string $documento): ?string
    {
        if ($documento === null) {
            return null;
        }
        // Espacios iniciales que PHP admite en un número (espacio, \t, \n, \r, \v, \f); un NBSP NO cuenta.
        if (preg_match('/^[ \t\n\r\v\f]*([+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)/', $documento, $m) !== 1) {
            return null;
        }

        return number_format((float) $m[1], 0, ',', '.');
    }

    /** La línea impresa: «TIPO: 73.156.827» (tipo vacío → `: 123`; documento sin prefijo numérico → `TIPO: `). */
    public static function lineaDocumento(?string $tipo, ?string $documento): string
    {
        return ($tipo ?? '').': '.(self::documento($documento) ?? '');
    }
}
