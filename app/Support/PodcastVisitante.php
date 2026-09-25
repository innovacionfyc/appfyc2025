<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Identidad anónima del visitante del podcast.
 *
 * - En el navegador solo viaja un UUID aleatorio, en una cookie cifrada por el middleware web,
 *   HttpOnly y SameSite=Lax. No identifica a la persona ni contiene datos suyos.
 * - En la base de datos solo se guarda el fingerprint: HMAC-SHA256 del UUID con APP_KEY.
 *   Ni APP_KEY ni el fingerprint salen nunca al navegador.
 */
final class PodcastVisitante
{
    public const COOKIE = 'podcast_visitante';

    private const MINUTOS = 60 * 24 * 365; // un año

    /**
     * UUID ya presente en la cookie (descifrada por el middleware) o null si no existe o no es válido.
     */
    public static function uuidDesde(Request $request): ?string
    {
        $valor = $request->cookie(self::COOKIE);

        return is_string($valor) && Str::isUuid($valor) ? $valor : null;
    }

    public static function nuevoUuid(): string
    {
        return (string) Str::uuid();
    }

    public static function fingerprint(string $uuid): string
    {
        return hash_hmac('sha256', $uuid, (string) config('app.key'));
    }

    /**
     * Cookie para adjuntar a la respuesta. El middleware EncryptCookies la cifra al salir.
     */
    public static function cookie(string $uuid): Cookie
    {
        return cookie(
            name: self::COOKIE,
            value: $uuid,
            minutes: self::MINUTOS,
            path: '/',
            domain: config('session.domain'),
            secure: config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }
}
