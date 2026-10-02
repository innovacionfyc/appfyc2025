<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Generacion\DatosCredencial;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use App\Support\CredentialFlow\Plantillas\ImagenAPdf;
use App\Support\CredentialFlow\Plantillas\ImagenInvalidaException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;

/**
 * Plantillas creadas desde una imagen PNG/JPG: se convierte UNA vez a PDF al crearla y desde ahí todo el módulo usa
 * ese `base.pdf` (editor, vista previa, QR, emisión). El PDF de siempre no cambia (ver PlantillasTest).
 */
class PlantillaImagenTest extends EmisionesTestCase
{
    // ── Imágenes de prueba (generadas con GD, sin binarios en el repo) ────────

    private function png(int $ancho = 1320, int $alto = 1020, bool $alfa = false): string
    {
        $im = imagecreatetruecolor($ancho, $alto);
        if ($alfa) {
            imagealphablending($im, false);
            imagesavealpha($im, true);
            imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        } else {
            imagefill($im, 0, 0, imagecolorallocate($im, 235, 240, 250));
        }
        imagefilledrectangle($im, 10, 10, intdiv($ancho, 4), intdiv($alto, 4), imagecolorallocate($im, 255, 0, 0));
        imagefilledrectangle($im, (int) ($ancho * 0.8), (int) ($alto * 0.8), $ancho - 10, $alto - 10, imagecolorallocate($im, 0, 0, 255));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function jpg(int $ancho = 1320, int $alto = 1020, int $orientacion = 1): string
    {
        $im = imagecreatetruecolor($ancho, $alto);
        imagefill($im, 0, 0, imagecolorallocate($im, 250, 245, 230));
        imagefilledrectangle($im, 10, 10, intdiv($ancho, 4), intdiv($alto, 4), imagecolorallocate($im, 255, 0, 0));
        ob_start();
        imagejpeg($im, null, 90);
        $bytes = (string) ob_get_clean();

        return $orientacion === 1 ? $bytes : $this->conOrientacionExif($bytes, $orientacion);
    }

    /** Inserta un APP1/EXIF mínimo con la etiqueta Orientation (0x0112). */
    private function conOrientacionExif(string $jpeg, int $orientacion): string
    {
        $tiff = "II\x2A\x00\x08\x00\x00\x00"           // cabecera little-endian, IFD en el offset 8
            ."\x01\x00"                                  // 1 entrada
            ."\x12\x01\x03\x00\x01\x00\x00\x00".pack('v', $orientacion)."\x00\x00" // Orientation, SHORT, 1 valor
            ."\x00\x00\x00\x00";                         // sin más IFD
        $app1 = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    private function subir(string $nombre, string $bytes, array $extra = [])
    {
        return $this->actingAs($this->admin())->post(route('credential-flow.plantillas.store'), array_merge([
            'nombre' => 'Desde imagen',
            'pdf' => UploadedFile::fake()->createWithContent($nombre, $bytes),
        ], $extra));
    }

    private function base(Plantilla $p): string
    {
        return Storage::disk('local')->get($p->rutaPdfEsperada());
    }

    // ── B / C. Imagen válida: se acepta y queda un base.pdf real ──────────────

    #[DataProvider('imagenesValidas')]
    public function test_una_imagen_valida_se_acepta_y_crea_base_pdf(string $nombre, string $fabrica): void
    {
        $bytes = $this->$fabrica();

        $this->subir($nombre, $bytes)->assertSessionHasNoErrors()->assertRedirect(route('credential-flow.plantillas.index'));

        $p = Plantilla::firstOrFail();
        $pdf = $this->base($p);
        $this->assertSame("credential-flow/plantillas/{$p->id}/base.pdf", $p->archivo_pdf);
        $this->assertSame($nombre, $p->nombre_archivo_original);
        $this->assertTrue((new InspectorPdf($pdf))->firmaValida());
        $this->assertSame(1, (new InspectorPdf($pdf))->paginas());
        $this->assertSame(hash('sha256', $pdf), $p->hash_sha256, 'El hash es el del PDF generado, no el de la imagen');
        $this->assertSame(['credential-flow/plantillas/'.$p->id.'/base.pdf'], Storage::disk('local')->allFiles(), 'Solo base.pdf: la imagen original NO se conserva');
    }

    public static function imagenesValidas(): array
    {
        return [
            'PNG' => ['fondo.png', 'png'],
            'JPG' => ['fondo.jpg', 'jpg'],
            'JPEG' => ['fondo.jpeg', 'jpg'],
            'extensión en mayúsculas' => ['FONDO.PNG', 'png'],
        ];
    }

    public function test_el_pdf_generado_es_legible_por_fpdi_y_lo_usa_el_motor_actual(): void
    {
        $this->subir('fondo.png', $this->png())->assertSessionHasNoErrors();
        $p = Plantilla::firstOrFail();

        $tamano = GeneradorCredencialPdf::tamanoPagina($this->base($p));

        $this->assertEqualsWithDelta(792.0, $tamano['width'], 0.01);
        $this->assertEqualsWithDelta(612.0, $tamano['height'], 0.01);
    }

    // ── I / J. Orientación y proporción ───────────────────────────────────────

    public static function proporciones(): array
    {
        return [
            'Carta horizontal 11:8,5' => [3300, 2550, 792.0, 612.0],
            'A4 horizontal' => [3508, 2480, 792.0, 559.91],
            'cuadrada' => [1000, 1000, 792.0, 792.0],
            'vertical' => [1500, 2000, 594.0, 792.0],
            'horizontal pequeña' => [800, 600, 792.0, 594.0],
        ];
    }

    #[DataProvider('proporciones')]
    public function test_el_lado_largo_mide_792_pt_la_proporcion_se_conserva_y_la_orientacion_se_respeta(int $w, int $h, float $anchoPt, float $altoPt): void
    {
        $r = ImagenAPdf::convertir($this->png($w, $h));

        $this->assertEqualsWithDelta($anchoPt, $r['ancho_pt'], 0.01);
        $this->assertEqualsWithDelta($altoPt, $r['alto_pt'], 0.01);
        $this->assertSame($r['ancho_pt'] >= $r['alto_pt'], $w >= $h, 'Orientación respetada');
        // Sin deformar: la proporción de la página es la de la imagen que se ve (que puede haberse reducido a 300 ppp).
        $this->assertEqualsWithDelta($r['ancho_px'] / $r['alto_px'], $r['ancho_pt'] / $r['alto_pt'], 0.002);

        $box = (new InspectorPdf($r['pdf']))->mediaBox();
        $this->assertEqualsWithDelta($anchoPt, $box[0], 0.01);
        $this->assertEqualsWithDelta($altoPt, $box[1], 0.01);
    }

    public function test_una_imagen_enorme_no_genera_una_pagina_enorme_y_se_reduce_a_300_ppp(): void
    {
        $r = ImagenAPdf::convertir($this->png(4600, 3200));

        $this->assertSame(792.0, max($r['ancho_pt'], $r['alto_pt']));
        $this->assertSame(ImagenAPdf::MAX_LADO_IMPRESION_PX, max($r['ancho_px'], $r['alto_px']));
        $this->assertSame(300, $r['ppp']);
    }

    public function test_los_ppp_efectivos_dependen_de_los_pixeles_y_no_del_tamano_de_la_pagina(): void
    {
        $this->assertSame(300, ImagenAPdf::convertir($this->png(3300, 2550))['ppp']);
        $this->assertSame(100, ImagenAPdf::convertir($this->png(1100, 850))['ppp']);
    }

    public function test_un_jpeg_con_orientacion_exif_se_gira_y_la_pagina_sigue_a_la_imagen_visible(): void
    {
        // Píxeles 400×300 con orientación 6 (girar 90° horario): se ve vertical.
        $r = ImagenAPdf::convertir($this->jpg(400, 300, 6));

        $this->assertLessThan($r['alto_pt'], $r['ancho_pt'], 'Quedó vertical');
        $this->assertSame(300, $r['ancho_px']);
        $this->assertSame(400, $r['alto_px']);
    }

    public function test_un_jpeg_sin_giro_se_incrusta_sin_recomprimir(): void
    {
        $bytes = $this->jpg(1320, 1020);

        $r = ImagenAPdf::convertir($bytes);

        $this->assertStringContainsString('/DCTDecode', $r['pdf']);
        $this->assertStringContainsString($bytes, $r['pdf'], 'Los bytes del JPEG van tal cual dentro del PDF');
    }

    /** Todo el contenido legible del PDF: bytes crudos + cada stream FlateDecode descomprimido (el texto de TCPDF va comprimido). */
    private function contenidoCompleto(string $pdf): string
    {
        $todo = $pdf;
        if (preg_match_all('/stream?
(.*?)?
endstream/s', $pdf, $m)) {
            foreach ($m[1] as $flujo) {
                $plano = @gzuncompress($flujo);
                if ($plano !== false) {
                    $todo .= '
'.$plano;
                }
            }
        }

        return $todo;
    }

    public function test_el_pdf_base_creado_desde_imagen_no_lleva_el_rotulo_powered_by_tcpdf(): void
    {
        $pdf = ImagenAPdf::convertir($this->png(660, 510))['pdf'];
        $inspector = new InspectorPdf($pdf);

        // Sigue siendo un PDF válido de 1 página, a la medida de la imagen y con la imagen incrustada.
        $this->assertTrue($inspector->firmaValida());
        $this->assertSame(1, $inspector->paginas());
        $this->assertEqualsWithDelta(792, $inspector->mediaBox()[0], 0.01);
        $this->assertEqualsWithDelta(612, $inspector->mediaBox()[1], 0.01);
        $this->assertSame(1, substr_count($pdf, '/Subtype /Image'));

        // Sin el rótulo (ni como texto ni como enlace), mirando también dentro de los streams comprimidos.
        $this->assertSame([], $inspector->textos(), 'la base solo contiene la imagen, ningún texto');
        $contenido = $this->contenidoCompleto($pdf);
        $this->assertStringNotContainsString('Powered by', $contenido);
        $this->assertStringNotContainsString('TCPDF (www', $contenido);
        $this->assertStringNotContainsString('/Subtype /Link', $contenido, 'tampoco el enlace del rótulo');
        $this->assertStringNotContainsString('/URI', $contenido);

        // Un certificado generado sobre este fondo no hereda el rótulo: solo lleva el texto del diseño.
        $diseno = ['page' => ['width' => 792, 'height' => 612], 'elements' => [[
            'id' => '00000000-0000-4000-8000-000000000001', 'type' => 'text', 'field' => null, 'text' => 'Hola', 'x' => 96, 'y' => 200, 'width' => 600, 'height' => 40,
            'fontFamily' => 'outfit', 'fontSize' => 22, 'fontWeight' => 700, 'color' => '#000000', 'align' => 'center',
        ]]];
        $certificado = GeneradorCredencialPdf::generarDesde($diseno, $pdf, DatosCredencial::qa(), 1);
        $this->assertSame(['Hola'], array_column((new InspectorPdf($certificado))->textos(), 'texto'));
        $this->assertStringNotContainsString('Powered by', $this->contenidoCompleto($certificado));
    }

    public function test_la_conversion_no_deja_archivos_temporales(): void
    {
        // TCPDF deja una copia temporal de cada imagen que recibe como cadena; el conversor debe evitarlo.
        $contar = fn () => count(array_filter(scandir(sys_get_temp_dir()), fn ($f) => str_starts_with($f, '__t') || str_starts_with($f, 'cfimg')));
        $antes = $contar();

        ImagenAPdf::convertir($this->png(800, 600));
        ImagenAPdf::convertir($this->jpg(800, 600));
        ImagenAPdf::convertir($this->png(600, 450, true));

        $this->assertSame($antes, $contar());
    }

    public function test_un_png_con_transparencia_queda_sobre_fondo_blanco(): void
    {
        $r = ImagenAPdf::convertir($this->png(600, 450, true));

        $this->assertSame('PNG', $r['formato']);
        $this->assertStringNotContainsString('/SMask', $r['pdf'], 'Sin canal alfa en el PDF');
    }

    // ── D / E / F / G. Rechazos ───────────────────────────────────────────────

    public static function archivosRechazados(): array
    {
        return [
            'D. extensión JPG con contenido inválido' => ['falso.jpg', 'esto no es una imagen'],
            'D2. extensión PNG con contenido vacío' => ['falso.png', ''],
            'E. SVG' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>'],
            'E2. SVG renombrado a PNG' => ['logo.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
            'F. GIF' => ['animado.gif', "GIF89a\x01\x00\x01\x00\x00\x00\x00;"],
            'F2. WEBP' => ['foto.webp', "RIFF\x24\x00\x00\x00WEBPVP8 "],
            'F3. HEIC' => ['foto.heic', "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00"],
            'F4. ejecutable renombrado a JPG' => ['programa.jpg', "MZ\x90\x00".str_repeat("\0", 64)],
            'F5. PHP renombrado a PNG' => ['shell.png', '<?php system($_GET["c"]); ?>'],
            'F6. doble extensión' => ['fondo.png.exe', "\x89PNG\r\n\x1a\n"],
        ];
    }

    #[DataProvider('archivosRechazados')]
    public function test_los_archivos_no_validos_se_rechazan_sin_dejar_nada(string $nombre, string $contenido): void
    {
        $this->subir($nombre, $contenido)->assertSessionHasErrors('pdf');

        $this->assertSame(0, Plantilla::withTrashed()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('local')->allDirectories(), 'Ni directorios huérfanos');
    }

    public function test_g_una_imagen_corrupta_se_rechaza_con_un_mensaje_entendible(): void
    {
        $valido = $this->jpg(800, 600);
        $truncado = substr($valido, 0, (int) (strlen($valido) * 0.6));

        $this->subir('cortada.jpg', $truncado)->assertSessionHasErrors('pdf');
        // PNG con IHDR válido pero datos dañados.
        $png = $this->png(300, 200);
        $danado = substr($png, 0, 60).str_repeat("\xAB", 200);
        $this->subir('danada.png', $danado)->assertSessionHasErrors('pdf');

        $this->assertSame(0, Plantilla::withTrashed()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('local')->allDirectories());
    }

    public function test_el_mensaje_de_error_no_usa_lenguaje_tecnico(): void
    {
        $r = $this->subir('falso.png', 'no es una imagen');

        $mensaje = session('errors')->first('pdf');
        $this->assertStringContainsString('PDF, PNG o JPG', $mensaje);
        $this->assertDoesNotMatchRegularExpression('/mime|exception|gd|getimagesize/i', $mensaje);
    }

    public function test_limites_de_dimensiones_y_cmyk(): void
    {
        foreach ([[8001, 100], [100, 8001], [4800, 3400]] as [$w, $h]) {
            try {
                ImagenAPdf::analizar($this->png($w, $h));
                $this->fail("Se esperaba rechazar {$w}x{$h}");
            } catch (ImagenInvalidaException $e) {
                $this->assertStringContainsString('demasiado grande', $e->getMessage());
            }
        }

        $this->expectException(ImagenInvalidaException::class);
        ImagenAPdf::analizar($this->png(2000, 100)); // 20:1: la página quedaría con menos de 100 pt de alto
    }

    // ── Tope de peso del PDF convertido (cada certificado emitido lo incluye) ──

    /** PNG de ruido (casi incomprimible, se incrusta tal cual) cuyo base.pdf pese ≈ $objetivo bytes. @return array{0:string,1:int} */
    private function ruidoConPdfDe(int $objetivo): array
    {
        $ruido = function (int $alto): string {
            $im = imagecreatetruecolor(1000, $alto);
            mt_srand(7);
            for ($y = 0; $y < $alto; $y++) {
                for ($x = 0; $x < 1000; $x++) {
                    imagesetpixel($im, $x, $y, imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
                }
            }
            ob_start();
            imagepng($im, null, 1);

            return (string) ob_get_clean();
        };

        // Sobrecosto de la estructura del PDF sobre el PNG incrustado tal cual.
        $sonda = $ruido(300);
        $sobrecosto = strlen(ImagenAPdf::convertir($sonda)['pdf']) - strlen($sonda);

        $alto = 400;
        for ($i = 0; $i < 5; $i++) {
            $png = $ruido($alto);
            try {
                $pdf = strlen(ImagenAPdf::convertir($png)['pdf']);
            } catch (ImagenInvalidaException) {
                $pdf = strlen($png) + $sobrecosto; // por encima del tope no hay PDF: se estima con el mismo sobrecosto
            }
            if (abs($pdf - $objetivo) < 25_000) {
                return [$png, $pdf];
            }
            $alto = max(300, (int) round($alto * $objetivo / $pdf));
        }

        $this->fail('No se pudo ajustar el PNG de ruido al peso objetivo.');
    }

    private function archivosTemporales(): int
    {
        return count(array_filter(scandir(sys_get_temp_dir()), fn ($f) => str_starts_with($f, '__t') || str_starts_with($f, 'cfimg')));
    }

    public function test_un_base_pdf_justo_por_debajo_de_3_mb_se_acepta(): void
    {
        $this->assertSame(3 * 1024 * 1024, ImagenAPdf::MAX_BYTES_PDF);
        [$png, $pdfBytes] = $this->ruidoConPdfDe(ImagenAPdf::MAX_BYTES_PDF - 60_000);
        $this->assertLessThan(ImagenAPdf::MAX_BYTES_PDF, $pdfBytes);
        $this->assertGreaterThan(ImagenAPdf::MAX_BYTES_PDF - 120_000, $pdfBytes, 'Está justo debajo del tope');
        $temporales = $this->archivosTemporales();

        $this->subir('justo.png', $png)->assertSessionHasNoErrors();

        $p = Plantilla::firstOrFail();
        $this->assertSame($pdfBytes, strlen($this->base($p)));
        $this->assertSame([$p->archivo_pdf], Storage::disk('local')->allFiles(), 'El PNG original no se conserva: solo base.pdf');
        $this->assertSame($temporales, $this->archivosTemporales(), 'Sin temporales residuales');
    }

    public function test_un_base_pdf_por_encima_de_3_mb_se_rechaza_sin_dejar_nada(): void
    {
        [$png, $pdfBytes] = $this->ruidoConPdfDe(ImagenAPdf::MAX_BYTES_PDF + 60_000);
        $this->assertGreaterThan(ImagenAPdf::MAX_BYTES_PDF, $pdfBytes);
        $this->assertLessThan(ImagenAPdf::MAX_BYTES_PDF + 120_000, $pdfBytes, 'Está justo encima del tope');
        $temporales = $this->archivosTemporales();

        $this->subir('pesado.png', $png)->assertSessionHasErrors('pdf');

        $mensaje = session('errors')->first('pdf');
        $this->assertStringContainsString('pesa demasiado', $mensaje);
        $this->assertStringContainsString('JPG', $mensaje, 'Sugiere una alternativa; no convierte nada en silencio');
        $this->assertSame(0, Plantilla::withTrashed()->count(), 'Sin registro en BD');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('local')->allDirectories(), 'Sin carpeta huérfana');
        $this->assertSame($temporales, $this->archivosTemporales(), 'Sin temporales residuales');
    }

    public function test_el_tope_aplica_a_la_conversion_directa(): void
    {
        [$png] = $this->ruidoConPdfDe(ImagenAPdf::MAX_BYTES_PDF + 60_000);

        $this->expectException(ImagenInvalidaException::class);
        $this->expectExceptionMessage('pesa demasiado');
        ImagenAPdf::convertir($png);
    }

    // ── H. Limpieza si falla la escritura ─────────────────────────────────────

    public function test_h_si_falla_la_escritura_no_queda_plantilla_ni_archivos(): void
    {
        $falla = \Mockery::mock(Filesystem::class);
        $falla->shouldReceive('put')->andReturn(false);
        $falla->shouldReceive('deleteDirectory')->atLeast()->once()->andReturn(true);
        Storage::shouldReceive('disk')->with(Plantilla::DISCO)->andReturn($falla);

        $this->subir('fondo.png', $this->png())->assertSessionHasErrors('general');

        $this->assertSame(0, Plantilla::withTrashed()->count(), 'La transacción revirtió el registro');
    }

    // ── A. El PDF de siempre sigue igual ──────────────────────────────────────

    public function test_a_un_pdf_sigue_guardandose_tal_cual(): void
    {
        $this->actingAs($this->admin())->post(route('credential-flow.plantillas.store'), ['nombre' => 'PDF de siempre', 'pdf' => $this->pdf('Original.pdf')])
            ->assertSessionHasNoErrors();

        $p = Plantilla::firstOrFail();
        $this->assertSame($this->pdfContenido(), $this->base($p), 'Los bytes del PDF no se tocan');
        $this->assertSame(hash('sha256', $this->pdfContenido()), $p->hash_sha256);
        $this->assertSame('Original.pdf', $p->nombre_archivo_original);
    }

    // ── K. Una plantilla desde imagen funciona en el resto del módulo ─────────

    public function test_k_editor_vista_previa_y_emision_funcionan_con_una_plantilla_desde_imagen(): void
    {
        $this->subir('fondo.jpg', $this->jpg(3300, 2550))->assertSessionHasNoErrors();
        $p = Plantilla::firstOrFail();

        // El editor recibe el PDF base como siempre.
        $r = $this->actingAs($this->admin())->get(route('credential-flow.plantillas.pdf', $p));
        $r->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));

        // Guarda un diseño (nombre + QR) con las medidas reales de la página.
        $elementos = [
            $this->elemento(['field' => 'nombre_completo', 'y' => 200]),
            ['id' => '00000000-0000-4000-8000-000000000099', 'type' => 'qr', 'x' => 640, 'y' => 40, 'width' => 100, 'height' => 100],
        ];
        $this->actingAs($this->admin())
            ->putJson(route('credential-flow.plantillas.diseno.update', $p), ['diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => $elementos]])
            ->assertRedirect(route('credential-flow.plantillas.editor', $p)); // un guardado correcto vuelve al editor

        // PDF de prueba.
        $prueba = $this->actingAs($this->admin())->get(route('credential-flow.plantillas.pdf-prueba', $p));
        $prueba->assertOk();
        $this->assertTrue((new InspectorPdf($prueba->getContent()))->firmaValida());

        // Emisión oficial de un certificado desde una base con esa plantilla.
        $lote = $this->loteCon(1, $p->fresh());
        $e = $this->emitirPor($lote, $lote->participantes()->first());
        $e->assertCreated()->assertJsonPath('emision.con_qr', true);
    }
}
