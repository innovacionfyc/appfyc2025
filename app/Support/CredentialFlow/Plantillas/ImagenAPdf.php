<?php

namespace App\Support\CredentialFlow\Plantillas;

use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Generacion\FuentesTcpdf;
use GdImage;
use TCPDF;

/**
 * Convierte UNA vez una imagen PNG/JPG en el PDF base de una plantilla. Desde ahí todo el módulo (editor, vista previa,
 * QR, emisión, reemisión, ZIP) trabaja con ese PDF exactamente igual que con uno subido: no hay ramas para imágenes.
 *
 * TAMAÑO FÍSICO. Los píxeles no definen el tamaño de la página (una foto de 4000 px no puede generar una página de metros):
 * el LADO LARGO de la página mide siempre LADO_LARGO_PT (792 pt = 11 in, el lado largo de una hoja Carta horizontal) y el
 * corto sale de la proporción de la imagen. Así una imagen 11:8,5 queda exactamente en Carta (792×612), una A4 (1,414:1)
 * queda en 792×560 pt, y nunca se deforma ni se recorta. La nitidez al imprimir depende de los píxeles: una imagen de
 * 3300 px de lado largo da 300 ppp a ese tamaño (se devuelve como ppp efectivos).
 *
 * PESO. Cada PDF emitido incrusta el PDF base completo, así que el peso del fondo se multiplica por cada certificado.
 * Por eso (1) una imagen de más de MAX_LADO_IMPRESION_PX de lado largo (300 ppp a ese tamaño) se reduce a ese límite y
 * (2) un fondo que, ya convertido, pese más de MAX_BYTES_PDF se rechaza con un mensaje claro en vez de degradarlo en silencio.
 *
 * El conversor no guarda nada: recibe bytes y devuelve bytes. GD solo decodifica para validar y normalizar (orientación
 * EXIF, transparencia sobre fondo blanco); un JPEG RGB sin rotar y un PNG RGB de 8 bits sin transparencia, dentro del
 * tope de resolución, se incrustan tal cual, sin recomprimir.
 */
final class ImagenAPdf
{
    /** Lado largo de la página resultante: 11 in en puntos (Carta horizontal). */
    public const LADO_LARGO_PT = 792.0;

    /** Límite de píxeles decodificables (≈ 4800×3300 = 400 ppp en Carta): protege la memoria del servidor. */
    public const MAX_PIXELES = 16_000_000;

    /** Lado máximo en píxeles. */
    public const MAX_LADO_PX = 8000;

    /** Lado largo máximo que se conserva: 300 ppp sobre 11 in. Una imagen mayor se reduce a este valor. */
    public const MAX_LADO_IMPRESION_PX = 3300;

    /** Peso máximo del PDF base generado desde una imagen (cada certificado emitido lo incluye). */
    public const MAX_BYTES_PDF = 3 * 1024 * 1024;

    /** Imagen HISTÓRICA incrustada tal cual (Fase 10B-2B-2A.1): la memoria depende del archivo, no de los píxeles; el tope solo acota el tiempo de validación. */
    public const MAX_PIXELES_INCRUSTADO = 150_000_000;

    public const MAX_LADO_INCRUSTADO = 20000;

    /** Por encima de este tamaño se advierte al administrador (los visores de PDF necesitan mucha memoria para dibujar la imagen). */
    public const UMBRAL_IMAGEN_MUY_GRANDE = 40_000_000;

    /** Calidad al recomprimir un JPEG que hubo que girar o reducir. */
    private const CALIDAD_JPEG = 95;

    /**
     * Los píxeles devueltos son los FINALES (tras reducir, si hizo falta).
     *
     * @return array{pdf:string, ancho_pt:float, alto_pt:float, ancho_px:int, alto_px:int, ppp:int, formato:string}
     *
     * @throws ImagenInvalidaException
     */
    public static function convertir(string $bytes, int $maxPixeles = self::MAX_PIXELES): array
    {
        $info = self::analizar($bytes, $maxPixeles);
        [$datos, $formato, $anchoFinal, $altoFinal] = self::normalizar($bytes, $info);

        // La página sale de los píxeles FINALES: tras girar por EXIF o reducir, la proporción es la que se ve.
        [$ancho, $alto] = self::tamanoPagina($anchoFinal, $altoFinal);
        $pdfBytes = self::construirPdf($datos, $formato, $ancho, $alto);

        return [
            'pdf' => $pdfBytes,
            'ancho_pt' => $ancho,
            'alto_pt' => $alto,
            'ancho_px' => $anchoFinal,
            'alto_px' => $altoFinal,
            'ppp' => (int) round(max($anchoFinal, $altoFinal) / (self::LADO_LARGO_PT / 72)),
            'formato' => $formato,
        ];
    }

    /**
     * PDF base de UNA página con la imagen a página completa y sin remuestreo. Compartido por `convertir` y `convertirHistorica`.
     *
     * @throws ImagenInvalidaException
     */
    private static function construirPdf(string $datos, string $formato, float $ancho, float $alto): string
    {
        $orientacion = $ancho >= $alto ? 'L' : 'P';

        FuentesTcpdf::configurar();
        // TCPDF, al cerrar el documento, escribe en la esquina inferior izquierda un rótulo de 1 pt «Powered by TCPDF» (con
        // enlace) mientras `tcpdflink` sea true; es una propiedad protegida, así que se apaga desde una subclase (igual
        // que hace GeneradorCredencialPdf). Así el PDF base queda solo con la imagen.
        $pdf = new class($orientacion, 'pt', [$ancho, $alto], true, 'UTF-8', false) extends TCPDF
        {
            public function __construct(...$args)
            {
                parent::__construct(...$args);
                $this->tcpdflink = false;
            }
        };
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setCompression(true);
        $pdf->SetCreator('F&C Credential Flow');
        $pdf->AddPage($orientacion, [$ancho, $alto]);

        // TCPDF, al recibir la imagen como cadena ('@bytes'), escribe una copia temporal que NO borra: una fuga de disco por
        // cada conversión. Se le pasa un archivo propio y se borra siempre al terminar.
        $temporal = tempnam(sys_get_temp_dir(), 'cfimg');
        try {
            if ($temporal === false || file_put_contents($temporal, $datos) === false) {
                throw new \RuntimeException('Sin archivo temporal.');
            }
            // Sin remuestreo (resize=false): la imagen se incrusta con todos sus píxeles, a página completa.
            $pdf->Image($temporal, 0, 0, $ancho, $alto, $formato, '', '', false, 300, '', false, false, 0, false, false, false);
            $pdfBytes = $pdf->Output('', 'S');
        } catch (\Throwable) {
            throw new ImagenInvalidaException('No se pudo preparar la imagen. Prueba con otro archivo PNG o JPG.');
        } finally {
            if (is_string($temporal)) {
                @unlink($temporal);
            }
        }

        if (strlen($pdfBytes) > self::MAX_BYTES_PDF) {
            throw new ImagenInvalidaException('La imagen pesa demasiado una vez preparada. Usa una más liviana (por ejemplo, un JPG).');
        }

        return $pdfBytes;
    }

    /**
     * Fondo de plantilla desde una imagen HISTÓRICA (contenido controlado, verificado por SHA-256), Fase 10B-2B-2A.1. Conserva la resolución original:
     *
     *  · PNG de 8 bits gris/RGB sin entrelazado ni transparencia, y JPEG sin giro EXIF ni CMYK → se valida SIN decodificar (ValidadorPng / estructura
     *    JPEG) y se incrusta el archivo ORIGINAL tal cual (TCPDF copia el flujo comprimido; no hay bitmap ni reducción). La memoria depende del tamaño del
     *    archivo (~1 MB), no de los píxeles: una imagen de 134 MP cabe en un PHP de 128 MB. Sin pérdida de calidad.
     *  · Cualquier otra → decodificación como siempre, pero SOLO si la estimación previa dice que cabe; si no, `ImagenExcedeCapacidadException`.
     *
     * @return array{pdf:string, ancho_pt:float, alto_pt:float, ancho_px:int, alto_px:int, ppp:int, formato:string, modo:string, advertencias:list<string>}
     *
     * @throws ImagenInvalidaException|ImagenExcedeCapacidadException
     */
    public static function convertirHistorica(string $bytes): array
    {
        if (! extension_loaded('gd')) {
            throw new ImagenInvalidaException('El servidor no puede procesar imágenes en este momento. Sube un PDF o avisa al equipo técnico.');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2] ?? null, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new ImagenInvalidaException('Este archivo no es un PNG o JPG válido.');
        }
        [$ancho, $alto] = [(int) $info[0], (int) $info[1]];
        if ($ancho < 1 || $alto < 1 || $ancho > self::MAX_LADO_INCRUSTADO || $alto > self::MAX_LADO_INCRUSTADO || $ancho * $alto > self::MAX_PIXELES_INCRUSTADO) {
            throw new ImagenInvalidaException('La imagen histórica supera el tamaño máximo admitido ('.(self::MAX_PIXELES_INCRUSTADO / 1_000_000).' megapíxeles).');
        }
        if ($info[2] === IMAGETYPE_JPEG && (int) ($info['channels'] ?? 3) === 4) {
            throw new ImagenInvalidaException('La imagen está en formato CMYK. Guárdala en RGB y vuelve a subirla.');
        }
        [, $corto] = self::tamanoPagina($ancho, $alto, true);
        if ($corto < DisenoSchema::PAGINA_MIN) {
            throw new ImagenInvalidaException('La imagen es demasiado alargada para un certificado.');
        }

        $esPng = $info[2] === IMAGETYPE_PNG;
        $incrustable = $esPng ? self::pngIncrustable($bytes) : self::orientacionExif($bytes) === 1;

        if ($incrustable) {
            $esPng ? ValidadorPng::validar($bytes) : self::validarEstructuraJpeg($bytes, $ancho, $alto);
            [$anchoPt, $altoPt] = self::tamanoPagina($ancho, $alto);
            $formato = $esPng ? 'PNG' : 'JPEG';
            $pdf = self::construirPdf($bytes, $formato, $anchoPt, $altoPt);
            [$anchoFinal, $altoFinal] = [$ancho, $alto];
            $modo = 'incrustacion_directa';
        } else {
            self::exigirCapacidadParaRasterizar($ancho, $alto);
            [$datos, $formato, $anchoFinal, $altoFinal] = self::normalizar($bytes, ['tipo' => (int) $info[2], 'ancho' => $ancho, 'alto' => $alto]);
            [$anchoPt, $altoPt] = self::tamanoPagina($anchoFinal, $altoFinal);
            $pdf = self::construirPdf($datos, $formato, $anchoPt, $altoPt);
            $modo = 'rasterizacion';
        }

        return [
            'pdf' => $pdf, 'ancho_pt' => $anchoPt, 'alto_pt' => $altoPt, 'ancho_px' => $anchoFinal, 'alto_px' => $altoFinal,
            'ppp' => (int) round(max($anchoFinal, $altoFinal) / (self::LADO_LARGO_PT / 72)), 'formato' => $formato, 'modo' => $modo,
            'advertencias' => $ancho * $alto > self::UMBRAL_IMAGEN_MUY_GRANDE ? ['IMAGEN_MUY_GRANDE'] : [],
        ];
    }

    /**
     * Estimación PREVIA de la memoria que necesita decodificar y reducir una imagen (el bitmap de GD ocupa 4 bytes por píxel, y `sobreFondoBlanco` hace
     * una segunda copia completa) frente a la disponible. Se rechaza ANTES de empezar: nunca un «Allowed memory size exhausted».
     *
     * @throws ImagenExcedeCapacidadException
     */
    public static function exigirCapacidadParaRasterizar(int $ancho, int $alto): void
    {
        $necesaria = self::estimarMemoriaRasterizacion($ancho, $alto);
        $limite = self::limiteMemoria();
        if ($limite === null) {
            return;
        }
        $disponible = $limite - memory_get_usage(true);
        if ($necesaria > $disponible) {
            throw new ImagenExcedeCapacidadException(
                'La imagen histórica es demasiado grande para procesarla en este servidor ('.round($necesaria / 1048576).' MB necesarios, '.max(0, (int) round($disponible / 1048576)).' MB disponibles) y no puede incrustarse sin decodificar. Debe procesarse en un entorno con más memoria.',
                $necesaria,
                max(0, $disponible),
            );
        }
    }

    /**
     * Bytes de memoria PHP por píxel que consume decodificar + copiar + reducir con GD, MEDIDOS (Fase 10B-2B-2A.1): un PNG RGB de 6600×5100 (33,7 MP)
     * llegó a 430 MB de pico (≈12,8 B/px: GD guarda 4 B/px por bitmap, libpng usa un búfer de filas y `sobreFondoBlanco` hace una copia completa);
     * uno de 3300×2550, ~134 MB. La estimación anterior (9 B/px) se quedaba corta: con memory_limit=384M pasaba la comprobación y terminaba en Fatal.
     */
    public const BYTES_POR_PIXEL_RASTERIZACION = 14;

    /** Memoria estimada (bytes) para rasterizar una imagen: píxeles × 14 B (con margen sobre lo medido) + 24 MB de base. */
    public static function estimarMemoriaRasterizacion(int $ancho, int $alto): int
    {
        return $ancho * $alto * self::BYTES_POR_PIXEL_RASTERIZACION + 24 * 1048576;
    }

    /**
     * Estructura de un JPEG sin decodificarlo: SOI, un marcador SOF con las dimensiones esperadas antes del primer SOS y el EOI final.
     *
     * @throws ImagenInvalidaException
     */
    private static function validarEstructuraJpeg(string $b, int $ancho, int $alto): void
    {
        $n = strlen($b);
        $danada = new ImagenInvalidaException('No se pudo leer la imagen: parece dañada. Prueba con otro archivo PNG o JPG.');
        if ($n < 4 || ! str_starts_with($b, "\xFF\xD8") || ! str_ends_with(rtrim($b, "\0\r\n "), "\xFF\xD9")) {
            throw $danada;
        }
        $pos = 2;
        $sof = false;
        while ($pos + 4 <= $n) {
            if ($b[$pos] !== "\xFF") {
                throw $danada;
            }
            $marcador = ord($b[$pos + 1]);
            if ($marcador === 0xFF) { // relleno
                $pos++;

                continue;
            }
            if ($marcador === 0xDA) { // SOS: empiezan los datos entropy-coded
                break;
            }
            $largo = (int) unpack('n', substr($b, $pos + 2, 2))[1];
            if ($largo < 2 || $pos + 2 + $largo > $n) {
                throw $danada;
            }
            if (in_array($marcador, [0xC0, 0xC1, 0xC2], true)) {
                $d = unpack('nalto/nancho', substr($b, $pos + 5, 4));
                $sof = $d['alto'] === $alto && $d['ancho'] === $ancho;
            }
            $pos += 2 + $largo;
        }
        if (! $sof) {
            throw $danada;
        }
    }

    /**
     * Comprueba lo que se puede saber sin decodificar: tipo REAL por contenido (no por extensión ni MIME del navegador),
     * dimensiones, límites y que no sea CMYK.
     *
     * `$maxPixeles` solo lo eleva un llamador de CONFIANZA con contenido controlado y verificado por SHA-256 (el clon de una plantilla histórica,
     * Fase 10B-2B-2A); las subidas de usuarios usan siempre el tope por defecto. La comprobación de memoria sigue aplicando.
     *
     * @return array{tipo:int, ancho:int, alto:int}
     *
     * @throws ImagenInvalidaException
     */
    public static function analizar(string $bytes, int $maxPixeles = self::MAX_PIXELES): array
    {
        if (! extension_loaded('gd')) {
            throw new ImagenInvalidaException('El servidor no puede procesar imágenes en este momento. Sube un PDF o avisa al equipo técnico.');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2] ?? null, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new ImagenInvalidaException('Este archivo no es un PDF, PNG o JPG válido.');
        }

        [$ancho, $alto] = [(int) $info[0], (int) $info[1]];
        if ($ancho < 1 || $alto < 1) {
            throw new ImagenInvalidaException('Este archivo no es un PDF, PNG o JPG válido.');
        }
        if ($ancho > self::MAX_LADO_PX || $alto > self::MAX_LADO_PX || $ancho * $alto > $maxPixeles) {
            throw new ImagenInvalidaException('La imagen es demasiado grande. Usa una de hasta 4800 × 3300 píxeles (unos 16 megapíxeles).');
        }
        if ($info[2] === IMAGETYPE_JPEG && (int) ($info['channels'] ?? 3) === 4) {
            throw new ImagenInvalidaException('La imagen está en formato CMYK. Guárdala en RGB y vuelve a subirla.');
        }

        // Archivo cortado: libjpeg/GD "rellenan" un JPEG truncado sin avisar, así que se exige el marcador de fin.
        $final = rtrim($bytes, "\0\r\n ");
        $completo = $info[2] === IMAGETYPE_JPEG
            ? str_ends_with($final, "\xFF\xD9")
            : str_ends_with($final, "IEND\xAE\x42\x60\x82");
        if (! $completo) {
            throw new ImagenInvalidaException('No se pudo leer la imagen: parece dañada. Prueba con otro archivo PNG o JPG.');
        }

        [, $corto] = self::tamanoPagina($ancho, $alto, true);
        if ($corto < DisenoSchema::PAGINA_MIN) {
            throw new ImagenInvalidaException('La imagen es demasiado alargada para un certificado.');
        }

        return ['tipo' => (int) $info[2], 'ancho' => $ancho, 'alto' => $alto];
    }

    /**
     * Página en puntos: el lado largo mide LADO_LARGO_PT y el corto conserva la proporción.
     *
     * @return array{0:float, 1:float} [ancho, alto], o [largo, corto] si $largoCorto
     */
    public static function tamanoPagina(int $anchoPx, int $altoPx, bool $largoCorto = false): array
    {
        $largo = self::LADO_LARGO_PT;
        $corto = round($largo * min($anchoPx, $altoPx) / max($anchoPx, $altoPx), 2);

        if ($largoCorto) {
            return [$largo, $corto];
        }

        return $anchoPx >= $altoPx ? [$largo, $corto] : [$corto, $largo];
    }

    /**
     * Devuelve [bytes listos para incrustar, 'JPEG'|'PNG', ancho final px, alto final px]. Valida que la imagen se pueda
     * decodificar.
     *
     * @param  array{tipo:int, ancho:int, alto:int}  $info
     * @return array{0:string, 1:string, 2:int, 3:int}
     */
    private static function normalizar(string $bytes, array $info): array
    {
        self::comprobarMemoria($info['ancho'], $info['alto']);

        $advertencias = 0;
        set_error_handler(function () use (&$advertencias) {
            $advertencias++;

            return true;
        });
        try {
            $im = imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }

        // Cualquier aviso de GD/libjpeg ("Premature end of JPEG file", datos corruptos…) cuenta como archivo dañado.
        if ($im === false || $advertencias > 0) {
            if ($im instanceof GdImage) {
                imagedestroy($im);
            }
            throw new ImagenInvalidaException('No se pudo leer la imagen: parece dañada. Prueba con otro archivo PNG o JPG.');
        }

        $excede = max($info['ancho'], $info['alto']) > self::MAX_LADO_IMPRESION_PX;

        if ($info['tipo'] === IMAGETYPE_JPEG) {
            $giro = self::orientacionExif($bytes);
            if ($giro === 1 && ! $excede) {
                return [$bytes, 'JPEG', $info['ancho'], $info['alto']]; // sin recomprimir: calidad y peso originales
            }

            $im = self::reducir(self::aplicarOrientacion($im, $giro));

            return [self::codificarJpeg($im), 'JPEG', imagesx($im), imagesy($im)];
        }

        // PNG RGB/gris de 8 bits, sin entrelazado ni transparencia: se incrusta tal cual.
        if (! $excede && self::pngIncrustable($bytes)) {
            return [$bytes, 'PNG', $info['ancho'], $info['alto']];
        }

        $im = self::reducir(self::sobreFondoBlanco($im));

        return [self::codificarPng($im), 'PNG', imagesx($im), imagesy($im)];
    }

    private static function comprobarMemoria(int $ancho, int $alto): void
    {
        $limite = self::limiteMemoria();
        if ($limite === null) {
            return;
        }

        // Estimación calibrada con mediciones reales (antes: 9 B/px, insuficiente).
        $necesaria = self::estimarMemoriaRasterizacion($ancho, $alto);
        if ($necesaria > $limite - memory_get_usage(true)) {
            throw new ImagenInvalidaException('La imagen es demasiado grande para procesarla. Usa una de menor resolución.');
        }
    }

    private static function limiteMemoria(): ?int
    {
        $v = trim((string) ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return null;
        }
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** Orientación EXIF de un JPEG (1 = normal o desconocida). */
    private static function orientacionExif(string $bytes): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $flujo = fopen('php://memory', 'r+b');
        fwrite($flujo, $bytes);
        rewind($flujo);
        $exif = @exif_read_data($flujo, 'IFD0');
        fclose($flujo);

        $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $o >= 2 && $o <= 8 ? $o : 1;
    }

    private static function aplicarOrientacion(GdImage $im, int $o): GdImage
    {
        // Valores EXIF: 2 espejo horizontal · 3 giro 180° · 4 espejo vertical · 5 transpuesta · 6 giro 90° horario ·
        // 7 transversa · 8 giro 90° antihorario. imagerotate() gira en sentido antihorario.
        if (in_array($o, [2, 5, 7], true)) {
            imageflip($im, IMG_FLIP_HORIZONTAL);
        } elseif ($o === 4) {
            imageflip($im, IMG_FLIP_VERTICAL);
        }

        $grados = match ($o) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };
        if ($grados !== 0) {
            $girada = imagerotate($im, $grados, 0xFFFFFF);
            if ($girada !== false) {
                return $girada;
            }
        }

        return $im;
    }

    /** Reduce (sin deformar) una imagen cuyo lado largo supere MAX_LADO_IMPRESION_PX. */
    private static function reducir(GdImage $im): GdImage
    {
        $ancho = imagesx($im);
        $alto = imagesy($im);
        $largo = max($ancho, $alto);
        if ($largo <= self::MAX_LADO_IMPRESION_PX) {
            return $im;
        }

        $f = self::MAX_LADO_IMPRESION_PX / $largo;
        $nuevoAncho = max(1, (int) round($ancho * $f));
        $nuevoAlto = max(1, (int) round($alto * $f));
        $destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));
        imagecopyresampled($destino, $im, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

        return $destino;
    }

    /** Coloca la imagen sobre un lienzo blanco: la transparencia nunca llega al PDF. */
    private static function sobreFondoBlanco(GdImage $im): GdImage
    {
        $ancho = imagesx($im);
        $alto = imagesy($im);
        $lienzo = imagecreatetruecolor($ancho, $alto);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
        imagealphablending($lienzo, true);
        imagecopy($lienzo, $im, 0, 0, 0, 0, $ancho, $alto);

        return $lienzo;
    }

    private static function codificarPng(GdImage $im): string
    {
        ob_start();
        imagepng($im, null, 6);

        return (string) ob_get_clean();
    }

    private static function codificarJpeg(GdImage $im): string
    {
        ob_start();
        imagejpeg(self::sobreFondoBlanco($im), null, self::CALIDAD_JPEG);

        return (string) ob_get_clean();
    }

    /** PNG de 8 bits en gris o RGB, sin entrelazado y sin transparencia (ni canal alfa ni chunk tRNS). */
    private static function pngIncrustable(string $bytes): bool
    {
        if (strlen($bytes) < 33 || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return false;
        }

        $profundidad = ord($bytes[24]);
        $tipoColor = ord($bytes[25]);
        $entrelazado = ord($bytes[28]);

        return $profundidad === 8 && in_array($tipoColor, [0, 2], true) && $entrelazado === 0 && ! str_contains($bytes, 'tRNS');
    }
}
