<?php

namespace App\Support\CredentialFlow\Portal;

use App\Models\CredentialFlow\CertificadoLegado;
use Normalizer;

/**
 * Normalización CONSERVADORA de nombre: la ÚNICA permitida para decidir qué certificados privados ve una persona (alcance de sesión).
 *
 * Hace SOLO: trim, minúsculas Unicode, quitar diacríticos/tildes, colapsar espacios y normalizar puntuación superficial (todo
 * símbolo que no sea letra o número pasa a espacio). NO usa Levenshtein, Jaccard, similitud aproximada, apellido omitido, orden de
 * nombres ni ninguna heurística: dos nombres son el mismo grupo si y solo si quedan idénticos tras estos pasos. La similitud difusa
 * pertenece a la auditoría y a la futura conciliación administrativa, nunca a la autorización.
 */
final class NombreConservador
{
    /** Respaldo si ext-intl no está disponible: diacríticos comunes del español y del latín occidental. */
    private const SIN_DIACRITICOS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y',
    ];

    public static function normalizar(string $nombre): string
    {
        $s = mb_strtolower(trim($nombre), 'UTF-8');
        if (class_exists(Normalizer::class)) {
            $descompuesto = Normalizer::normalize($s, Normalizer::FORM_D);
            $s = preg_replace('/\p{Mn}/u', '', $descompuesto === false ? $s : $descompuesto) ?? $s;
        } else {
            $s = strtr($s, self::SIN_DIACRITICOS);
        }
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** Identificador estable y opaco del grupo (HMAC con el secreto de la aplicación): es lo único que viaja al desafío OTP y a la sesión. */
    public static function grupoId(string $nombre): string
    {
        return Hmac::de('grupo', self::normalizar($nombre));
    }

    public static function grupoDe(CertificadoLegado $c): string
    {
        return self::grupoId((string) $c->nombre_completo);
    }
}
