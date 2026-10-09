<?php

namespace App\Support\CredentialFlow\Portal;

/** HMAC-SHA256 con el secreto de la aplicación: lo único que el portal persiste de documento, correo, IP y user-agent. */
final class Hmac
{
    public static function de(string $dominio, string $valor): string
    {
        return hash_hmac('sha256', $dominio."\0".$valor, (string) config('app.key'));
    }

    public static function ip(?string $ip): ?string
    {
        return $ip === null || $ip === '' ? null : self::de('ip', $ip);
    }
}
