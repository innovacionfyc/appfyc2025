<?php

namespace App\Support\CredentialFlow\Generacion;

use App\Support\CredentialFlow\FuentesCredential;
use Composer\InstalledVersions;
use RuntimeException;

/**
 * Integración de las fuentes de Credential Flow con TCPDF 6. Solo resuelve nombres y rutas de las
 * definiciones TCPDF (.php + .z + .ctg.z) versionadas en resources/fonts/credential-flow/tcpdf/.
 * La autoridad sobre archivos, pesos, métricas y cobertura sigue siendo FuentesCredential.
 *
 * Las definiciones se generan con `php artisan credential-flow:fuentes-tcpdf` a partir de los TTF
 * versionados y se comprueban con `--verify` (nombre, mapeo, widths contra metricas.json y tamaño
 * original). Nunca se generan en runtime.
 */
final class FuentesTcpdf
{
    /**
     * peso => [nombre de la definición TCPDF, nombre PostScript que TCPDF escribe en ella].
     * Los nombres los deriva TCPDF del archivo (por eso no siguen un patrón uniforme).
     */
    public const DEFINICIONES = [
        300 => ['outfitlight', 'Outfit-Light'],
        400 => ['outfit', 'Outfit-Regular'],
        500 => ['outfitmedium', 'Outfit-Medium'],
        600 => ['outfitsemib', 'Outfit-SemiBold'],
        700 => ['outfitb', 'Outfit-Bold'],
        800 => ['outfitextrab', 'Outfit-ExtraBold'],
    ];

    public static function directorio(): string
    {
        return FuentesCredential::directorio().'/tcpdf';
    }

    /** Nombre de la definición TCPDF para una familia y peso reproducibles. */
    public static function nombre(string $familia, int $peso): string
    {
        if (! FuentesCredential::esReproducible($familia, $peso) || ! isset(self::DEFINICIONES[$peso])) {
            throw new RuntimeException("Sin definición TCPDF para {$familia} {$peso}.");
        }

        return self::DEFINICIONES[$peso][0];
    }

    /**
     * Constantes que TCPDF 6 exige antes de cargarse (equivalen a un tcpdf_config.php propio).
     * Se definen una sola vez; el directorio de fuentes es el versionado en el repositorio.
     */
    public static function configurar(): void
    {
        if (defined('K_TCPDF_EXTERNAL_CONFIG')) {
            return;
        }

        // Sin autocargar la clase TCPDF: al cargarse leería su propio tcpdf_config.php.
        $tcpdf = rtrim(InstalledVersions::getInstallPath('tecnickcom/tcpdf'), '/\\').'/';

        define('K_TCPDF_EXTERNAL_CONFIG', true);
        define('K_PATH_MAIN', $tcpdf);
        define('K_PATH_URL', $tcpdf);
        define('K_PATH_FONTS', self::directorio().'/');
        define('K_PATH_IMAGES', $tcpdf.'images/');
        define('K_BLANK_IMAGE', $tcpdf.'images/_blank.png');
        // Solo lo usa TCPDF para máscaras de imagen (no se dibujan imágenes en esta fase).
        define('K_PATH_CACHE', rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR);
        define('K_CELL_HEIGHT_RATIO', 1.25);
        define('K_TITLE_MAGNIFICATION', 1.3);
        define('K_SMALL_RATIO', 2 / 3);
        define('K_THAI_TOPCHARS', true);
        define('K_TCPDF_CALLS_IN_HTML', false);
        define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
        define('K_TIMEZONE', 'America/Bogota');
        define('PDF_PAGE_FORMAT', 'A4');
        define('PDF_PAGE_ORIENTATION', 'P');
        define('PDF_CREATOR', 'F&C Credential Flow');
        define('PDF_UNIT', 'pt');
        define('PDF_MARGIN_HEADER', 0);
        define('PDF_MARGIN_FOOTER', 0);
        define('PDF_MARGIN_TOP', 0);
        define('PDF_MARGIN_BOTTOM', 0);
        define('PDF_MARGIN_LEFT', 0);
        define('PDF_MARGIN_RIGHT', 0);
        define('PDF_FONT_NAME_MAIN', 'outfitb');
        define('PDF_FONT_SIZE_MAIN', 12);
        define('PDF_FONT_NAME_DATA', 'outfitb');
        define('PDF_FONT_SIZE_DATA', 12);
        define('PDF_FONT_MONOSPACED', 'outfitb');
        define('PDF_IMAGE_SCALE_RATIO', 1.25);
        define('HEAD_MAGNIFICATION', 1.1);
    }
}
