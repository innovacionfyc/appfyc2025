<?php

namespace App\Support\CredentialFlow\Legado;

use RuntimeException;

/**
 * Carga AISLADA y verificada de FPDF 1.81 (la versión exacta del sistema viejo), sin modificarla.
 *
 *  - El archivo vive en resources/legado/fpdf-1.81 (fuera de vendor/ y del autoload): ni composer ni TCPDF/FPDI se enteran.
 *  - Antes de incluirlo se comprueba su SHA-256 (si Git o alguien lo altera, el renderer se NIEGA a arrancar).
 *  - Si ya hay otra clase `FPDF` cargada que no es esta, también se niega (no mezclar versiones).
 *  - Solo se añade a PHP el shim de get_magic_quotes_runtime() (ver shim_php8.php); nada más global.
 */
final class Fpdf181
{
    public const VERSION = '1.81';

    /** SHA-256 de fpdf.php tal como se distribuye (fpdf181.zip oficial, con sus finales de línea originales). */
    public const SHA256 = '46145eac510e4ec4fb93738c0aa8b6719df896f284e7cdb715a001226d8ec863';

    private static bool $cargado = false;

    public static function directorio(): string
    {
        return resource_path('legado/fpdf-1.81');
    }

    public static function rutaFpdf(): string
    {
        return self::directorio().DIRECTORY_SEPARATOR.'fpdf.php';
    }

    /** Verifica y carga FPDF 1.81 y la subclase determinista. Idempotente. */
    public static function cargar(): void
    {
        if (self::$cargado) {
            return;
        }

        $ruta = self::rutaFpdf();
        $real = is_file($ruta) ? hash_file('sha256', $ruta) : false;
        if ($real !== self::SHA256) {
            throw new RuntimeException('FPDF 1.81 no está íntegro (falta o su SHA-256 no coincide): el renderer legado no arranca.');
        }

        if (class_exists('FPDF', false) && ! in_array(realpath($ruta), array_map('realpath', get_included_files()), true)) {
            throw new RuntimeException('Ya hay otra clase FPDF cargada que no es la 1.81 del renderer legado.');
        }

        require_once __DIR__.DIRECTORY_SEPARATOR.'shim_php8.php';
        require_once $ruta;
        if (! defined('FPDF_VERSION') || FPDF_VERSION !== self::VERSION) {
            throw new RuntimeException('La versión de FPDF cargada no es la 1.81.');
        }
        require_once __DIR__.DIRECTORY_SEPARATOR.'PdfCertificadoLegado.php';

        self::$cargado = true;
    }
}
