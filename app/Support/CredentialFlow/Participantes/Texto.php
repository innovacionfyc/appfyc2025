<?php

namespace App\Support\CredentialFlow\Participantes;

/** Normalización y comprobaciones de texto compartidas por el importador y el alta manual. */
final class Texto
{
    public static function utf8Valido(string $s): bool
    {
        return mb_check_encoding($s, 'UTF-8');
    }

    /**
     * NFC, NBSP → espacio, tabs y saltos de línea → espacio, espacios repetidos → uno, sin espacios
     * en los bordes. No altera mayúsculas ni caracteres imprimibles.
     */
    public static function limpiar(string $s): string
    {
        $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
        $s = $n === false ? $s : $n;
        $s = preg_replace('/[\x{00A0}\x{2007}\x{202F}]/u', ' ', $s) ?? $s;
        $s = preg_replace('/[\t\r\n\x{0085}\x{2028}\x{2029}]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/ {2,}/u', ' ', $s) ?? $s;

        return trim($s);
    }

    /** Mayúsculas Unicode y NFC (algunas conversiones pueden descomponer). */
    public static function mayusculas(string $s): string
    {
        $m = mb_strtoupper($s, 'UTF-8');
        $n = \Normalizer::normalize($m, \Normalizer::FORM_C);

        return $n === false ? $m : $n;
    }

    public static function tieneControl(string $s): bool
    {
        return preg_match('/\p{Cc}/u', $s) === 1;
    }

    public static function iniciaConFormula(string $s): bool
    {
        return $s !== '' && in_array($s[0], ['=', '+', '-', '@'], true);
    }

    /** Cantidad de letras y dígitos (para exigir un mínimo de contenido en un documento). */
    public static function alfanumericos(string $s): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}]/u', $s);
    }

    /** `C.C. 1.023.456.789` → `CC1023456789`. Solo sirve para detectar duplicados. */
    public static function claveDocumento(string $documento): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', self::mayusculas($documento)) ?? '';
    }

    /** Encabezado comparable: sin mayúsculas, tildes ni separadores (`Número de Documento` → `numerodedocumento`). */
    public static function encabezado(string $s): string
    {
        $s = mb_strtolower(self::limpiar($s), 'UTF-8');
        $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
        $s = $d === false ? $s : $d;
        $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;

        return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
    }
}
