<?php

namespace App\Support\CredentialFlow\Verificacion;

/**
 * URL pública de verificación de una emisión: {base_url}/verificar/{codigo}. Es lo ÚNICO que contiene un QR.
 * El código de emisión (cf_emisiones.codigo) es la identidad; el QR es solo una representación visual de esta URL.
 */
final class UrlVerificacion
{
    /** Código de ejemplo (alfabeto Crockford válido) para el preview del editor y el PDF de prueba. No existe en BD. */
    public const CODIGO_EJEMPLO = 'QA234567ABCDEFGHJKMN';

    public static function base(): string
    {
        $base = config('credential_flow.verificacion.base_url');
        if (! is_string($base) || trim($base) === '') {
            $base = (string) config('app.url');
        }

        return rtrim(trim($base), '/');
    }

    public static function para(string $codigo): string
    {
        return self::base().'/verificar/'.$codigo;
    }

    public static function ejemplo(): string
    {
        return self::para(self::CODIGO_EJEMPLO);
    }
}
