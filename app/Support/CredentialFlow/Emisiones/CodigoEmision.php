<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\CredentialFlow\Emision;

/**
 * Código único de emisión: 20 caracteres Crockford Base32 aleatorios (5 bits cada uno = 100 bits). No se
 * deriva del id, no es secuencial y no revela la hora. Se genera ANTES de renderizar el PDF (no se imprime
 * en esta fase). Existe un índice UNIQUE; la colisión es prácticamente imposible pero se reintenta.
 */
final class CodigoEmision
{
    public const ALFABETO = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LONGITUD = 20;

    /** Solo para tests: sustituye la fuente de códigos (p. ej. para simular una colisión). */
    public static ?\Closure $fuente = null;

    public static function generar(): string
    {
        if (self::$fuente !== null) {
            return (self::$fuente)();
        }

        $bytes = random_bytes(self::LONGITUD);
        $codigo = '';
        for ($i = 0; $i < self::LONGITUD; $i++) {
            // 256 es múltiplo de 32: enmascarar 5 bits no introduce sesgo.
            $codigo .= self::ALFABETO[ord($bytes[$i]) & 31];
        }

        return $codigo;
    }

    /** Un código que no exista todavía (reintenta ante una colisión). */
    public static function unico(int $intentos = 10): string
    {
        for ($i = 0; $i < $intentos; $i++) {
            $codigo = self::generar();
            if (! Emision::where('codigo', $codigo)->exists()) {
                return $codigo;
            }
        }

        throw new EmisionException(EmisionException::ERROR_ESCRITURA, 'No se pudo generar un código de emisión único.', 500);
    }

    public static function valido(string $codigo): bool
    {
        return preg_match('/^['.self::ALFABETO.']{'.self::LONGITUD.'}$/', $codigo) === 1;
    }
}
