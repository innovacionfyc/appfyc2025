<?php

namespace Tests\Feature\CredentialFlow\Plantillas;

use App\Support\CredentialFlow\Generacion\DatosCredencial;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use App\Support\CredentialFlow\Plantillas\ImagenAPdf;
use App\Support\CredentialFlow\Plantillas\ImagenExcedeCapacidadException;
use App\Support\CredentialFlow\Plantillas\ImagenInvalidaException;
use App\Support\CredentialFlow\Reemplazo\DisenoReemplazo;
use Symfony\Component\Process\Process;
use Tests\Feature\CredentialFlow\Support\PngSintetico;
use Tests\TestCase;

/** Conversión de imágenes HISTÓRICAS a PDF base con memoria acotada (Fase 10B-2B-2A.1): se incrusta el original, sin decodificar ni reducir. */
class ImagenAPdfHistoricaTest extends TestCase
{
    private function temporales(): int
    {
        return count(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'cfimg*') ?: []);
    }

    private function jpeg(int $ancho, int $alto): string
    {
        $im = imagecreatetruecolor($ancho, $alto);
        imagefill($im, 0, 0, imagecolorallocate($im, 250, 245, 230));
        imagefilledrectangle($im, 10, 10, intdiv($ancho, 4), intdiv($alto, 4), imagecolorallocate($im, 255, 0, 0));
        ob_start();
        imagejpeg($im, null, 90);

        return (string) ob_get_clean();
    }

    private function conOrientacionExif(string $jpeg, int $orientacion): string
    {
        $tiff = "II\x2A\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00".pack('v', $orientacion)."\x00\x00\x00\x00\x00\x00";
        $app1 = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    public function test_un_png_de_337_megapixeles_se_incrusta_tal_cual_sin_perdida_y_sin_decodificar(): void
    {
        $png = PngSintetico::crear(6600, 5100);
        memory_reset_peak_usage();
        $antes = memory_get_usage();

        $r = ImagenAPdf::convertirHistorica($png);

        $this->assertLessThan(64 * 1048576, memory_get_peak_usage() - $antes, 'sin bitmap: la memoria depende del archivo, no de los píxeles');
        $this->assertSame(['incrustacion_directa', 6600, 5100, 'PNG', []], [$r['modo'], $r['ancho_px'], $r['alto_px'], $r['formato'], $r['advertencias']]);
        $this->assertSame([792.0, 612.0], [$r['ancho_pt'], $r['alto_pt']]);
        $this->assertStringStartsWith('%PDF', $r['pdf']);
        $this->assertLessThanOrEqual(ImagenAPdf::MAX_BYTES_PDF, strlen($r['pdf']));
        // Sin pérdida por construcción: el flujo de la imagen dentro del PDF ES el IDAT del PNG original.
        $this->assertSame(PngSintetico::datosIdat($png), PngSintetico::flujoDeImagenPdf($r['pdf']));
        $this->assertStringContainsString('/Width 6600', $r['pdf']);
        $this->assertStringContainsString('/Height 5100', $r['pdf']);
    }

    public function test_el_pdf_resultante_lo_usa_el_motor_de_emision_y_conserva_la_geometria(): void
    {
        $r = ImagenAPdf::convertirHistorica(PngSintetico::crear(6600, 5100));

        $pagina = GeneradorCredencialPdf::tamanoPagina($r['pdf']);
        $diseno = DisenoReemplazo::inicial($pagina['width'], $pagina['height']);
        $datos = DatosCredencial::fromArray(['nombre_completo' => 'PERSONA SINTETICA', 'documento' => '12345678', 'evento' => 'EVENTO', 'fecha' => '', 'intensidad_horaria' => '']);
        $final = GeneradorCredencialPdf::generarDesde($diseno, $r['pdf'], $datos, 3, [], 'https://example.test/verificar/ABCDEFGHJKMNPQRSTVWX');

        $this->assertEquals(['width' => 792.0, 'height' => 612.0], $pagina);
        $this->assertStringStartsWith('%PDF', $final);
        $this->assertLessThan(3 * 1048576, strlen($final));
    }

    public function test_una_imagen_de_134_megapixeles_se_incrusta_con_advertencia(): void
    {
        $r = ImagenAPdf::convertirHistorica(PngSintetico::crear(13200, 10200));

        $this->assertSame(['incrustacion_directa', 13200, 10200, ['IMAGEN_MUY_GRANDE']], [$r['modo'], $r['ancho_px'], $r['alto_px'], $r['advertencias']]);
        $this->assertLessThanOrEqual(ImagenAPdf::MAX_BYTES_PDF, strlen($r['pdf']));
    }

    public function test_un_jpeg_se_incrusta_sin_recomprimir(): void
    {
        $jpg = $this->jpeg(3000, 2000);

        $r = ImagenAPdf::convertirHistorica($jpg);

        $this->assertSame(['incrustacion_directa', 'JPEG', 3000, 2000], [$r['modo'], $r['formato'], $r['ancho_px'], $r['alto_px']]);
        $this->assertSame($jpg, PngSintetico::flujoDeImagenPdf($r['pdf']), 'el flujo DCT del PDF es el JPEG original, byte a byte');
    }

    public function test_un_jpeg_con_giro_exif_y_un_png_con_transparencia_se_rasterizan_con_memoria_estimada(): void
    {
        $girado = ImagenAPdf::convertirHistorica($this->conOrientacionExif($this->jpeg(1200, 800), 6));
        $this->assertSame('rasterizacion', $girado['modo']);
        $this->assertGreaterThan($girado['ancho_pt'], $girado['alto_pt'], 'la página sigue a la imagen visible (girada)');

        $im = imagecreatetruecolor(400, 300);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        ob_start();
        imagepng($im);
        $alfa = ImagenAPdf::convertirHistorica((string) ob_get_clean());
        $this->assertSame('rasterizacion', $alfa['modo']);
        $this->assertStringStartsWith('%PDF', $alfa['pdf']);
    }

    public function test_si_no_hay_memoria_para_rasterizar_falla_antes_de_decodificar_con_un_error_controlado(): void
    {
        $alfa = PngSintetico::crear(6600, 5100, 6);   // RGBA: no se puede incrustar y exigiría ~350 MB
        $limite = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 96 * 1048576));
        memory_reset_peak_usage();
        $antes = memory_get_usage();

        try {
            ImagenAPdf::convertirHistorica($alfa);
            $this->fail('Debió fallar de forma controlada');
        } catch (ImagenExcedeCapacidadException $e) {
            $this->assertGreaterThan($e->disponibleBytes, $e->necesariaBytes);
            $this->assertSame('PLANTILLA_HISTORICA_EXCEDE_CAPACIDAD', ImagenExcedeCapacidadException::CODIGO);
            $this->assertStringContainsString('más memoria', $e->getMessage());
            $this->assertInstanceOf(ImagenInvalidaException::class, $e);
        } finally {
            ini_set('memory_limit', (string) $limite);
        }
        $this->assertLessThan(24 * 1048576, memory_get_peak_usage() - $antes, 'se rechazó ANTES de decodificar');
    }

    public function test_con_384_mb_el_flujo_antiguo_terminaba_en_fatal_y_ahora_se_rechaza_antes_de_decodificar(): void
    {
        $alfa = PngSintetico::crear(6600, 5100, 6);
        $limite = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 330 * 1048576));   // más que los 9 B/px antiguos (303 MB), menos que el pico real (430 MB)

        try {
            ImagenAPdf::convertirHistorica($alfa);
            $this->fail('Debió rechazarse antes de decodificar');
        } catch (ImagenExcedeCapacidadException) {
            $this->addToAssertionCount(1);
        } finally {
            ini_set('memory_limit', (string) $limite);
        }
    }

    public function test_el_estimador_crece_con_los_pixeles_y_cubre_el_pico_medido(): void
    {
        $e = fn (int $w, int $h) => ImagenAPdf::estimarMemoriaRasterizacion($w, $h);

        $this->assertGreaterThan($e(3300, 2550), $e(6600, 5100));
        // Picos MEDIDOS del flujo de rasterización: 6600×5100 → 430 MB; 3300×2550 → ~134 MB. La estimación debe cubrirlos (antes se quedaba corta).
        $this->assertGreaterThan(430 * 1048576, $e(6600, 5100));
        $this->assertGreaterThan(134 * 1048576, $e(3300, 2550));
        $this->assertLessThan(160 * 1048576, $e(3300, 2550));
    }

    public function test_los_archivos_danados_o_fuera_de_limites_se_rechazan_sin_dejar_temporales(): void
    {
        $antes = $this->temporales();
        $bueno = PngSintetico::crear(300, 200);
        $jpg = $this->jpeg(400, 300);
        $casos = [
            'png truncado' => substr($bueno, 0, -40),
            'png con crc alterado' => substr_replace($bueno, "\x00", 60, 1),
            'jpeg truncado' => substr($jpg, 0, intdiv(strlen($jpg), 2)),
            'jpeg sin EOI' => substr($jpg, 0, -2),
            'no es imagen' => 'hola',
            'png de 156 MP' => PngSintetico::ensamblar([['IHDR', pack('NNCCCCC', 13000, 12000, 8, 2, 0, 0, 0)], ['IEND', '']]),
            'demasiado alargada' => PngSintetico::crear(4000, 4),
        ];
        foreach ($casos as $nombre => $bytes) {
            try {
                ImagenAPdf::convertirHistorica($bytes);
                $this->fail("Debió rechazar: $nombre");
            } catch (ImagenInvalidaException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($antes, $this->temporales());
    }

    public function test_una_conversion_correcta_no_deja_temporales(): void
    {
        $antes = $this->temporales();

        ImagenAPdf::convertirHistorica(PngSintetico::crear(800, 600));
        ImagenAPdf::convertirHistorica($this->jpeg(800, 600));

        $this->assertSame($antes, $this->temporales());
    }

    public function test_con_128_mb_de_memoria_php_las_imagenes_grandes_completan_y_el_pico_queda_bajo_96_mb(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mem128_'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/grande.png', PngSintetico::crear(6600, 5100));
        file_put_contents($dir.'/gigante.png', PngSintetico::crear(13200, 10200));
        file_put_contents($dir.'/alfa.png', PngSintetico::crear(6600, 5100, 6));
        $raiz = var_export(base_path(), true);
        $script = <<<PHP
        <?php
        require {$raiz}.'/vendor/autoload.php';
        \$app = require {$raiz}.'/bootstrap/app.php';
        \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        \$bytes = file_get_contents(\$argv[1]);
        try {
            \$r = App\\Support\\CredentialFlow\\Plantillas\\ImagenAPdf::convertirHistorica(\$bytes);
            \$p = App\\Support\\CredentialFlow\\Generacion\\GeneradorCredencialPdf::tamanoPagina(\$r['pdf']);
            \$d = App\\Support\\CredentialFlow\\Reemplazo\\DisenoReemplazo::inicial(\$p['width'], \$p['height']);
            \$datos = App\\Support\\CredentialFlow\\Generacion\\DatosCredencial::fromArray(['nombre_completo' => 'PERSONA', 'documento' => '12345678', 'evento' => 'E', 'fecha' => '', 'intensidad_horaria' => '']);
            \$f = App\\Support\\CredentialFlow\\Generacion\\GeneradorCredencialPdf::generarDesde(\$d, \$r['pdf'], \$datos, 3, [], 'https://example.test/verificar/ABCDEFGHJKMNPQRSTVWX');
            echo json_encode(['ok' => true, 'modo' => \$r['modo'], 'pdf' => strlen(\$r['pdf']), 'final' => strlen(\$f), 'pico' => memory_get_peak_usage(true)]);
        } catch (App\\Support\\CredentialFlow\\Plantillas\\ImagenExcedeCapacidadException \$e) {
            echo json_encode(['ok' => false, 'codigo' => \$e::CODIGO, 'pico' => memory_get_peak_usage(true)]);
        }
        PHP;
        file_put_contents($dir.'/worker.php', $script);

        try {
            $correr = function (string $archivo) use ($dir) {
                $p = new Process([PHP_BINARY, '-d', 'memory_limit=128M', $dir.'/worker.php', $dir.'/'.$archivo], base_path());
                $p->run();
                $this->assertSame(0, $p->getExitCode(), $archivo.': '.$p->getErrorOutput().$p->getOutput());
                $this->assertStringNotContainsString('Allowed memory size', $p->getErrorOutput().$p->getOutput());

                return json_decode(trim($p->getOutput()), true);
            };

            foreach (['grande.png', 'gigante.png'] as $archivo) {
                $r = $correr($archivo);
                $this->assertTrue($r['ok'], $archivo);
                $this->assertSame('incrustacion_directa', $r['modo']);
                $this->assertLessThanOrEqual(96 * 1048576, $r['pico'], "$archivo: pico PHP ≤ 96 MB con memory_limit=128M");
            }
            // La que NO se puede incrustar falla ANTES, con un código controlado, nunca con un Fatal.
            $alfa = $correr('alfa.png');
            $this->assertFalse($alfa['ok']);
            $this->assertSame('PLANTILLA_HISTORICA_EXCEDE_CAPACIDAD', $alfa['codigo']);
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }
}
