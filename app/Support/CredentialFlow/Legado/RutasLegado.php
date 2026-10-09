<?php

namespace App\Support\CredentialFlow\Legado;

use InvalidArgumentException;

/**
 * Rutas FUTURAS (relativas al disco privado `local`, storage/app/private) de los archivos del módulo histórico. Solo
 * construye y valida rutas: no crea, copia ni lee nada. Todo se deriva de valores validados (SHA-256 en hexadecimal, id
 * entero, extensión de una lista cerrada): nunca de un nombre de archivo del sistema viejo.
 *
 *   plantillas:   credential-flow/legado/plantillas/{sha256}/original.{ext}      (una sola copia por contenido)
 *   certificados: credential-flow/legado/certificados/{id}/certificado.pdf       (PDF congelado)
 */
final class RutasLegado
{
    public const RAIZ = 'credential-flow/legado';

    /** Extensiones del blob según el tipo REAL del contenido (la extensión del nombre original se conserva en el catálogo). */
    public const EXTENSIONES = ['png', 'jpg', 'gif'];

    public static function plantilla(string $sha256, string $extension): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('El SHA-256 de la plantilla no es válido.');
        }
        $extension = strtolower($extension);
        if (! in_array($extension, self::EXTENSIONES, true)) {
            throw new InvalidArgumentException('La extensión del blob debe ser png, jpg o gif.');
        }

        return self::RAIZ.'/plantillas/'.$sha256.'/original.'.$extension;
    }

    public static function certificado(int $id): string
    {
        if ($id < 1) {
            throw new InvalidArgumentException('El id del certificado no es válido.');
        }

        return self::RAIZ.'/certificados/'.$id.'/certificado.pdf';
    }

    /** Extensión del blob a partir del MIME real detectado al catalogar (null si no es un tipo soportado). */
    public static function extensionPorMime(?string $mime): ?string
    {
        return ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'][$mime ?? ''] ?? null;
    }
}
