<?php

namespace App\Support;

/**
 * Utilidades puras para URLs de YouTube: sin HTTP, sin API key, sin OAuth.
 */
final class YouTubeUrl
{
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    private const HOSTS_YOUTUBE = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'music.youtube.com',
        'www.youtube-nocookie.com',
        'youtube-nocookie.com',
    ];

    private const HOSTS_CORTOS = ['youtu.be', 'www.youtu.be'];

    private const PREFIJOS_RUTA = ['shorts', 'live', 'embed', 'v'];

    private const CALIDADES_MINIATURA = ['default', 'mqdefault', 'hqdefault', 'sddefault', 'maxresdefault'];

    public static function esIdValido(?string $id): bool
    {
        return $id !== null && preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * Extrae el ID de video de cualquier formato habitual de URL de YouTube.
     * Devuelve null si la URL no es de YouTube o no contiene un ID válido.
     */
    public static function extraerId(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }

        $partes = parse_url($url);
        if ($partes === false || empty($partes['host'])) {
            return null;
        }

        $host = strtolower($partes['host']);
        $segmentos = array_values(array_filter(explode('/', $partes['path'] ?? ''), 'strlen'));
        $candidato = null;

        if (in_array($host, self::HOSTS_CORTOS, true)) {
            $candidato = $segmentos[0] ?? null;
        } elseif (in_array($host, self::HOSTS_YOUTUBE, true)) {
            $primero = $segmentos[0] ?? '';

            if ($primero === 'watch') {
                parse_str($partes['query'] ?? '', $query);
                $candidato = $query['v'] ?? null;
            } elseif (in_array($primero, self::PREFIJOS_RUTA, true)) {
                $candidato = $segmentos[1] ?? null;
            }
        }

        return self::esIdValido($candidato) ? $candidato : null;
    }

    /**
     * URL de reproducción en modo privacidad mejorada (youtube-nocookie.com).
     */
    public static function embedUrl(string $videoId, array $parametros = []): string
    {
        $parametros = array_merge(['rel' => 0, 'modestbranding' => 1], $parametros);

        return 'https://www.youtube-nocookie.com/embed/' . $videoId . '?' . http_build_query($parametros);
    }

    public static function thumbnailUrl(string $videoId, string $calidad = 'hqdefault'): string
    {
        if (!in_array($calidad, self::CALIDADES_MINIATURA, true)) {
            $calidad = 'hqdefault';
        }

        return 'https://i.ytimg.com/vi/' . $videoId . '/' . $calidad . '.jpg';
    }

    public static function watchUrl(string $videoId): string
    {
        return 'https://www.youtube.com/watch?v=' . $videoId;
    }
}
