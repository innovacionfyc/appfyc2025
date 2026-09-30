<?php

namespace App\Support\CredentialFlow\Generacion;

use App\Support\CredentialFlow\DisenoSchema;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Dibuja el QR de verificación con TCPDF (vectorial: no hay imagen ni archivo). La caja completa del elemento
 * incluye la zona de silencio (QR_QUIET_MODULOS módulos por lado) y un fondo blanco; el QR es negro, ECC M.
 * El único contenido codificado es la URL pública de verificación.
 */
final class DibujanteQr
{
    /** Solo para tests: recibe los parámetros exactos con los que se llama a write2DBarcode. */
    public static ?\Closure $observador = null;

    /** @param  array{id:string, x:float, y:float, size:float}  $plan */
    public static function dibujar(Fpdi $pdf, array $plan, string $url): void
    {
        $tipo = 'QRCODE,'.DisenoSchema::QR_ECC;
        $estilo = [
            'position' => '',
            'border' => false,
            'padding' => DisenoSchema::QR_QUIET_MODULOS, // en MÓDULOS y dentro de la caja
            'fgcolor' => [0, 0, 0],
            'bgcolor' => [255, 255, 255],
            'module_width' => 1,
            'module_height' => 1,
        ];

        if (self::$observador !== null) {
            (self::$observador)(['url' => $url, 'tipo' => $tipo, 'x' => $plan['x'], 'y' => $plan['y'], 'w' => $plan['size'], 'h' => $plan['size'], 'estilo' => $estilo]);
        }

        $pdf->write2DBarcode($url, $tipo, $plan['x'], $plan['y'], $plan['size'], $plan['size'], $estilo, '', false);
    }
}
