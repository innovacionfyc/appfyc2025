<?php

namespace Tests\Feature\CredentialFlow\Support;

/**
 * PNG sintéticos construidos A MANO (sin GD): permiten probar imágenes de 33,7 MP o de 134 MP sin crear un bitmap en memoria (se comprimen fila a fila),
 * y fabricar archivos malformados a medida (CRC alterado, IDAT truncado, más datos que píxeles…). Sin binarios en el repositorio.
 */
final class PngSintetico
{
    public const FIRMA = "\x89PNG\r\n\x1a\n";

    /** PNG de un solo color con barras horizontales (para que no sea trivial). Tipos de color: 0 gris · 2 RGB · 4 gris+alfa · 6 RGBA. */
    public static function crear(int $ancho, int $alto, int $color = 2, int $profundidad = 8, int $entrelazado = 0): string
    {
        return self::ensamblar([
            ['IHDR', pack('NNCCCCC', $ancho, $alto, $profundidad, $color, 0, 0, $entrelazado)],
            ...self::trozos(self::flujoZlib($ancho, $alto, $color, $profundidad)),
            ['IEND', ''],
        ]);
    }

    /** Flujo zlib (datos de IDAT) de la imagen sintética, calculado fila a fila. */
    public static function flujoZlib(int $ancho, int $alto, int $color = 2, int $profundidad = 8): string
    {
        $canales = [0 => 1, 2 => 3, 4 => 2, 6 => 4][$color];
        $bytesFila = $ancho * $canales * intdiv(max(8, $profundidad), 8);
        $blanca = "\0".str_repeat("\xFF", $bytesFila);
        $barra = "\0".str_repeat("\x40", $bytesFila);
        $ctx = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 6]);
        $z = '';
        for ($y = 0; $y < $alto; $y++) {
            $z .= deflate_add($ctx, $y % 400 < 8 ? $barra : $blanca, ZLIB_NO_FLUSH);
        }

        return $z.deflate_add($ctx, '', ZLIB_FINISH);
    }

    /** @return list<array{0:string,1:string}> chunks IDAT de `$tam` bytes */
    public static function trozos(string $z, int $tam = 32768): array
    {
        $r = [];
        foreach (str_split($z, $tam) as $t) {
            $r[] = ['IDAT', $t];
        }

        return $r;
    }

    /** @param list<array{0:string,1:string}> $partes [tipo, datos] */
    public static function ensamblar(array $partes): string
    {
        $png = self::FIRMA;
        foreach ($partes as [$tipo, $datos]) {
            $png .= pack('N', strlen($datos)).$tipo.$datos.pack('N', crc32($tipo.$datos));
        }

        return $png;
    }

    /** Concatenación de los datos de todos los IDAT de un PNG (lo que TCPDF debe incrustar sin tocar). */
    public static function datosIdat(string $png): string
    {
        $z = '';
        for ($i = 8, $n = strlen($png); $i + 12 <= $n;) {
            $len = unpack('N', substr($png, $i, 4))[1];
            if (substr($png, $i + 4, 4) === 'IDAT') {
                $z .= substr($png, $i + 8, $len);
            }
            $i += 12 + $len;
        }

        return $z;
    }

    /** Bytes del flujo de la primera imagen (XObject /Subtype /Image) de un PDF. */
    public static function flujoDeImagenPdf(string $pdf): ?string
    {
        $i = strpos($pdf, '/Subtype /Image');
        if ($i === false) {
            return null;
        }
        $inicio = strpos($pdf, "stream\n", $i);
        $fin = $inicio === false ? false : strpos($pdf, "\nendstream", $inicio);
        if ($inicio === false || $fin === false) {
            return null;
        }

        return substr($pdf, $inicio + 7, $fin - $inicio - 7);
    }
}
