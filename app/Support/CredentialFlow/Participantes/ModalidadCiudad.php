<?php

namespace App\Support\CredentialFlow\Participantes;

/**
 * Valor del campo dinámico DERIVADO «modalidad_ciudad»: la modalidad (la de la persona o, si no tiene, la general de la base) + «, » + la ciudad de la base,
 * en una sola línea. Es la ÚNICA regla de composición: la usan los datos del certificado (DatosDeParticipante) y la vista previa del asistente.
 *
 *  - La modalidad se imprime LITERAL (no se normaliza ni se traduce: «Virtual sincrónico» sigue siendo «Virtual sincrónico»). Solo se limpian los espacios
 *    sobrantes y un separador colgado al final («Presencial,» → «Presencial»).
 *  - Ciudad vacía → solo la modalidad (sin coma sobrante).
 *  - Modalidad vacía → null (el campo no tiene valor): la emisión se bloquea antes de generar, igual que con «modalidad». Nunca se imprime «, Bogotá» ni la
 *    ciudad sola en una línea que dice ser la modalidad.
 *  - Si la modalidad ya nombra la ciudad (sin distinguir mayúsculas, tildes ni puntuación, y como palabras completas) no se repite.
 */
final class ModalidadCiudad
{
    public const SEPARADOR = ', ';

    public static function componer(?string $modalidad, ?string $ciudad): ?string
    {
        $m = self::limpiar((string) $modalidad);
        // Un separador colgado al final de la modalidad o al principio de la ciudad no se duplica («Presencial,» / «, Bogotá»).
        $m = (string) preg_replace('/[\s,;:·\-–—]+$/u', '', $m);
        $c = (string) preg_replace('/^[\s,;:·\-–—]+|[\s,;:·\-–—]+$/u', '', self::limpiar((string) $ciudad));
        if ($m === '') {
            return null;
        }
        if ($c === '' || self::incluye($m, $c)) {
            return $m;
        }

        return $m.self::SEPARADOR.$c;
    }

    /** ¿La modalidad ya nombra la ciudad? Comparación sin mayúsculas, sin tildes, sin puntuación y por palabras completas. */
    public static function incluye(string $modalidad, string $ciudad): bool
    {
        $c = self::clave($ciudad);
        if ($c === '') {
            return false;
        }

        return preg_match('/(?:^| )'.preg_quote($c, '/').'(?: |$)/u', self::clave($modalidad)) === 1;
    }

    private static function limpiar(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    private static function clave(string $s): string
    {
        $nfd = \Normalizer::normalize(mb_strtolower($s, 'UTF-8'), \Normalizer::FORM_D);
        $sinTildes = (string) preg_replace('/\p{Mn}+/u', '', $nfd === false ? mb_strtolower($s, 'UTF-8') : $nfd);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $sinTildes));
    }
}
