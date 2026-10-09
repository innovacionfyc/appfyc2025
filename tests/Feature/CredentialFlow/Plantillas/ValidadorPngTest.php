<?php

namespace Tests\Feature\CredentialFlow\Plantillas;

use App\Support\CredentialFlow\Plantillas\ImagenInvalidaException;
use App\Support\CredentialFlow\Plantillas\ValidadorPng;
use Tests\Feature\CredentialFlow\Support\PngSintetico;
use Tests\TestCase;

/** Validación estructural de un PNG SIN decodificarlo (Fase 10B-2B-2A.1). */
class ValidadorPngTest extends TestCase
{
    private function rechaza(string $png, string $contiene = 'dañada'): void
    {
        try {
            ValidadorPng::validar($png);
            $this->fail('Debió rechazarse');
        } catch (ImagenInvalidaException $e) {
            $this->assertStringContainsString($contiene, $e->getMessage());
        }
    }

    public function test_un_png_valido_de_gd_y_uno_sintetico_pasan_con_la_longitud_exacta(): void
    {
        $im = imagecreatetruecolor(120, 80);
        imagefilledrectangle($im, 5, 5, 60, 40, imagecolorallocate($im, 255, 0, 0));
        ob_start();
        imagepng($im);
        $gd = (string) ob_get_clean();

        $a = ValidadorPng::validar($gd);
        $b = ValidadorPng::validar(PngSintetico::crear(300, 200));
        $gris = ValidadorPng::validar(PngSintetico::crear(300, 200, 0));

        $this->assertSame([120, 80, 120 * 3 * 80 + 80], [$a['ancho'], $a['alto'], $a['bytes_descomprimidos']]);
        $this->assertSame(200 * (1 + 300 * 3), $b['bytes_descomprimidos']);
        $this->assertSame(200 * (1 + 300), $gris['bytes_descomprimidos']);
    }

    public function test_un_png_de_337_megapixeles_se_valida_sin_crear_el_bitmap(): void
    {
        $png = PngSintetico::crear(6600, 5100);   // ~101 MB descomprimidos, ~0,3 MB comprimidos
        memory_reset_peak_usage();
        $antes = memory_get_usage();

        $r = ValidadorPng::validar($png);

        $this->assertSame(5100 * (1 + 6600 * 3), $r['bytes_descomprimidos']);
        $this->assertLessThan(48 * 1048576, memory_get_peak_usage() - $antes, 'la validación no depende de los píxeles: inflado en rebanadas');
    }

    public function test_detecta_archivos_truncados_con_crc_alterado_sin_iend_o_con_basura(): void
    {
        $bueno = PngSintetico::crear(200, 100);

        $this->rechaza(substr($bueno, 0, strlen($bueno) - 30));                                      // truncado
        $this->rechaza(substr($bueno, 0, strlen($bueno) - 12));                                      // sin IEND
        $malo = $bueno;
        $malo[40] = $malo[40] ^ "\x01";                                                              // un bit dentro de un chunk: CRC distinto
        $this->rechaza($malo);
        $this->rechaza($bueno.'basura al final');                                                    // datos tras IEND
        $this->assertSame(100 * (1 + 200 * 3), ValidadorPng::validar($bueno."\0\r\n ")['bytes_descomprimidos'], 'el relleno de espacios/ceros final sí se tolera');
        $this->rechaza('no es un png');
    }

    public function test_detecta_que_los_datos_no_coinciden_con_las_dimensiones(): void
    {
        $ihdr = fn (int $w, int $h) => ['IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)];
        // El IDAT describe 200×100 pero el encabezado dice 200×101 (faltan datos) o 200×99 (sobran).
        $z = PngSintetico::flujoZlib(200, 100);
        $this->rechaza(PngSintetico::ensamblar([$ihdr(200, 101), ...PngSintetico::trozos($z), ['IEND', '']]));
        $this->rechaza(PngSintetico::ensamblar([$ihdr(200, 99), ...PngSintetico::trozos($z), ['IEND', '']]));
        // Flujo zlib cortado a la mitad.
        $this->rechaza(PngSintetico::ensamblar([$ihdr(200, 100), ...PngSintetico::trozos(substr($z, 0, intdiv(strlen($z), 2))), ['IEND', '']]));
        // IDAT que no es zlib.
        $this->rechaza(PngSintetico::ensamblar([$ihdr(200, 100), ['IDAT', str_repeat("\x07", 500)], ['IEND', '']]));
        // Sin IDAT.
        $this->rechaza(PngSintetico::ensamblar([$ihdr(200, 100), ['IEND', '']]));
    }

    public function test_una_bomba_de_descompresion_se_corta_sin_reservar_memoria(): void
    {
        // El encabezado promete 10×10 píxeles, pero el flujo infla a ~64 MB de ceros: se corta en cuanto excede lo esperado.
        $ctx = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        $z = '';
        for ($i = 0; $i < 64; $i++) {
            $z .= deflate_add($ctx, str_repeat("\0", 1048576), ZLIB_NO_FLUSH);
        }
        $z .= deflate_add($ctx, '', ZLIB_FINISH);
        $png = PngSintetico::ensamblar([['IHDR', pack('NNCCCCC', 10, 10, 8, 2, 0, 0, 0)], ...PngSintetico::trozos($z), ['IEND', '']]);
        memory_reset_peak_usage();
        $antes = memory_get_usage();

        $this->rechaza($png);

        $this->assertLessThan(24 * 1048576, memory_get_peak_usage() - $antes);
    }

    public function test_los_idat_deben_ser_contiguos_y_el_primer_chunk_debe_ser_ihdr(): void
    {
        $z = PngSintetico::flujoZlib(100, 50);
        $trozos = PngSintetico::trozos($z, 40);
        $ihdr = ['IHDR', pack('NNCCCCC', 100, 50, 8, 2, 0, 0, 0)];
        $this->assertGreaterThan(2, count($trozos));

        $this->rechaza(PngSintetico::ensamblar([$ihdr, $trozos[0], ['tEXt', 'a'], ...array_slice($trozos, 1), ['IEND', '']]));
        $this->rechaza(PngSintetico::ensamblar([['tEXt', 'a'], $ihdr, ...$trozos, ['IEND', '']]));
        $this->assertSame(50 * (1 + 300), ValidadorPng::validar(PngSintetico::ensamblar([$ihdr, ['pHYs', str_repeat("\0", 9)], ...$trozos, ['tEXt', 'k'], ['IEND', '']]))['bytes_descomprimidos']);
    }

    public function test_solo_acepta_png_de_8_bits_gris_o_rgb_sin_entrelazado(): void
    {
        $this->rechaza(PngSintetico::crear(100, 50, 6), 'no se puede incrustar');       // RGBA
        $this->rechaza(PngSintetico::crear(100, 50, 2, 8, 1), 'no se puede incrustar'); // entrelazado
        $this->rechaza(PngSintetico::crear(100, 50, 2, 16), 'no se puede incrustar');   // 16 bits
    }
}
