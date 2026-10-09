<?php

namespace App\Support\CredentialFlow\Plantillas;

/**
 * Validación ESTRUCTURAL de un PNG sin decodificarlo a un bitmap (Fase 10B-2B-2A.1). Un PNG de 33,7 MP ocupa ~135 MB al decodificarse con GD, pero
 * solo ~1 MB comprimido: aquí se recorren los chunks, se comprueba el CRC de cada uno y se INFLA el flujo IDAT en rebanadas pequeñas, descartando la
 * salida y contando solo su longitud, que debe ser exactamente `alto × (1 + bytes por fila)`. Así se detecta un archivo truncado, corrupto o con datos
 * de menos/de más, con memoria acotada (~8 MB como máximo por rebanada, incluso con una compresión de 1000:1) y sin un bitmap en memoria.
 *
 * Solo para PNG de 8 bits en gris o RGB, sin entrelazado (lo que `ImagenAPdf::pngIncrustable` ya admite para incrustar tal cual).
 */
final class ValidadorPng
{
    private const FIRMA = "\x89PNG\r\n\x1a\n";

    /** Bytes de entrada por llamada a inflate: la salida máxima por llamada es ~1032 veces esto (límite teórico de deflate). */
    private const REBANADA = 8192;

    /**
     * @return array{ancho:int,alto:int,profundidad:int,color:int,bytes_idat:int,bytes_descomprimidos:int}
     *
     * @throws ImagenInvalidaException
     */
    public static function validar(string $b): array
    {
        $n = strlen($b);
        if ($n < 57 || ! str_starts_with($b, self::FIRMA)) {
            throw self::danada();
        }

        $pos = 8;
        $ihdr = null;
        $esperado = 0;
        $salida = 0;
        $bytesIdat = 0;
        $ctx = null;
        $idatCerrado = false;
        $iend = false;

        while ($pos + 12 <= $n && ! $iend) {
            $len = (int) unpack('N', substr($b, $pos, 4))[1];
            $tipo = substr($b, $pos + 4, 4);
            if ($len > 0x7FFFFFFF || $pos + 12 + $len > $n) {
                throw self::danada();
            }
            $crcEsperado = (int) unpack('N', substr($b, $pos + 8 + $len, 4))[1];
            if ($crcEsperado !== crc32(substr($b, $pos + 4, 4 + $len))) {
                throw self::danada();
            }

            if ($ihdr === null) {
                if ($tipo !== 'IHDR' || $len !== 13) {
                    throw self::danada();
                }
                $h = unpack('Nancho/Nalto/Cprofundidad/Ccolor/Ccompresion/Cfiltro/Centrelazado', substr($b, $pos + 8, 13));
                $canales = [0 => 1, 2 => 3][$h['color']] ?? 0;
                if ($h['profundidad'] !== 8 || $canales === 0 || $h['compresion'] !== 0 || $h['filtro'] !== 0 || $h['entrelazado'] !== 0 || $h['ancho'] < 1 || $h['alto'] < 1) {
                    throw new ImagenInvalidaException('Este PNG no se puede incrustar sin decodificar (solo gris o RGB de 8 bits, sin entrelazado).');
                }
                $esperado = $h['alto'] * (1 + $h['ancho'] * $canales);
                $ihdr = $h;
            } elseif ($tipo === 'IDAT') {
                if ($idatCerrado) {
                    throw self::danada(); // los IDAT deben ser contiguos
                }
                $ctx ??= inflate_init(ZLIB_ENCODING_DEFLATE);
                if ($ctx === false) {
                    throw self::danada();
                }
                $bytesIdat += $len;
                for ($i = 0; $i < $len; $i += self::REBANADA) {
                    $trozo = @inflate_add($ctx, substr($b, $pos + 8 + $i, min(self::REBANADA, $len - $i)));
                    if ($trozo === false) {
                        throw self::danada();
                    }
                    $salida += strlen($trozo);
                    unset($trozo);
                    if ($salida > $esperado) {
                        throw self::danada(); // más datos que píxeles: archivo manipulado o bomba de descompresión
                    }
                }
            } else {
                if ($ctx !== null) {
                    $idatCerrado = true;
                }
                $iend = $tipo === 'IEND';
                if ($iend && $len !== 0) {
                    throw self::danada();
                }
            }
            $pos += 12 + $len;
        }

        if ($ihdr === null || ! $iend || $ctx === null) {
            throw self::danada();
        }
        // Lo normal es que el flujo ya haya terminado por sí solo con el último IDAT; si no, se fuerza el cierre (un flujo cortado da error).
        if (inflate_get_status($ctx) !== ZLIB_STREAM_END) {
            $resto = @inflate_add($ctx, '', ZLIB_FINISH);
            if ($resto === false) {
                throw self::danada();
            }
            $salida += strlen($resto);
        }
        if ($salida !== $esperado || inflate_get_status($ctx) !== ZLIB_STREAM_END) {
            throw self::danada();
        }
        if (rtrim(substr($b, $pos), "\0\r\n ") !== '') {
            throw self::danada(); // basura tras IEND
        }

        return ['ancho' => $ihdr['ancho'], 'alto' => $ihdr['alto'], 'profundidad' => $ihdr['profundidad'], 'color' => $ihdr['color'], 'bytes_idat' => $bytesIdat, 'bytes_descomprimidos' => $salida];
    }

    private static function danada(): ImagenInvalidaException
    {
        return new ImagenInvalidaException('No se pudo leer la imagen: parece dañada. Prueba con otro archivo PNG o JPG.');
    }
}
