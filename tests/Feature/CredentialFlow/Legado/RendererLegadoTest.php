<?php

namespace Tests\Feature\CredentialFlow\Legado;

use App\Models\CredentialFlow\PlantillaLegado;
use App\Support\CredentialFlow\Legado\Fpdf181;
use App\Support\CredentialFlow\Legado\PlantillaImagen;
use App\Support\CredentialFlow\Legado\RendererLegado;
use App\Support\CredentialFlow\Legado\RenderNoPermitido;
use App\Support\CredentialFlow\Legado\SolicitudRender;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El renderer histórico con imágenes y datos 100 % sintéticos (generados con GD, sin PII). La comparación contra el
 * sistema viejo real (PHP 7.4 + FPDF 1.81) se hace en el QA visual documentado, no aquí.
 */
class RendererLegadoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legado_'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    private function imagen(string $tipo = 'png', int $ancho = 120, int $alto = 85): string
    {
        $im = imagecreatetruecolor($ancho, $alto);
        imagefill($im, 0, 0, imagecolorallocate($im, 230, 240, 250));
        imagefilledrectangle($im, 5, 5, $ancho - 6, 15, imagecolorallocate($im, 20, 60, 120));
        $ruta = $this->dir.DIRECTORY_SEPARATOR.'fondo_'.bin2hex(random_bytes(3)).'.'.$tipo;
        match ($tipo) {
            'png' => imagepng($im, $ruta),
            'jpg' => imagejpeg($im, $ruta, 90),
            'gif' => imagegif($im, $ruta),
        };

        return $ruta;
    }

    private function plantilla(?string $ruta = null, array $c = []): PlantillaImagen
    {
        $ruta ??= $this->imagen();

        return new PlantillaImagen(
            $ruta,
            array_key_exists('sha', $c) ? $c['sha'] : (is_file($ruta) ? hash_file('sha256', $ruta) : null),
            array_key_exists('ext', $c) ? $c['ext'] : 'png',
            $c['estado'] ?? PlantillaLegado::ESTADO_OK,
            $c['renderizable'] ?? true,
        );
    }

    private function solicitud(?PlantillaImagen $p = null, array $c = []): SolicitudRender
    {
        return new SolicitudRender(
            $p ?? $this->plantilla(),
            $c['nombre'] ?? 'PERSONA DE PRUEBA',
            array_key_exists('tipo', $c) ? $c['tipo'] : 'CC',
            array_key_exists('doc', $c) ? $c['doc'] : '12345678',
            array_key_exists('codigo', $c) ? $c['codigo'] : '5237',
            $c['fecha'] ?? new DateTimeImmutable('2026-10-05 13:48:02', new \DateTimeZone('UTC')),
        );
    }

    /** Ancho en puntos de un texto en Helvetica-Bold, medido con la propia FPDF (para comprobar el centrado). */
    private function anchoAprox(string $texto, int $tamano): float
    {
        Fpdf181::cargar();
        $pdf = new \FPDF('L', 'mm', 'A4');
        $pdf->SetFont('Arial', 'B', $tamano);

        return $pdf->GetStringWidth($texto) * 72 / 25.4;
    }

    private function renderer(): RendererLegado
    {
        return new RendererLegado;
    }

    private function negado(SolicitudRender $s, string $codigo): void
    {
        try {
            $this->renderer()->render($s);
            $this->fail("Debía negarse con $codigo");
        } catch (RenderNoPermitido $e) {
            $this->assertSame($codigo, $e->codigo);
        }
    }

    // ── Render correcto ───────────────────────────────────────────────────────

    #[DataProvider('tiposDeImagen')]
    public function test_renderiza_png_jpg_y_gif_en_una_pagina_a4_horizontal(string $tipo): void
    {
        $ruta = $this->imagen($tipo);
        $r = $this->renderer()->render($this->solicitud($this->plantilla($ruta, ['ext' => $tipo])));

        $this->assertStringStartsWith('%PDF-1.3', $r->pdf);
        $this->assertSame(strlen($r->pdf), $r->bytes);
        $this->assertSame(hash('sha256', $r->pdf), $r->sha256);
        $this->assertSame(1, $r->metadata['paginas']);
        $this->assertSame(1, preg_match_all('#/Type /Page\b#', $r->pdf));
        $this->assertStringContainsString('/MediaBox [0 0 841.89 595.28]', $r->pdf, 'A4 horizontal (297 x 210 mm)');
        $this->assertStringContainsString('/BaseFont /Helvetica-Bold', $r->pdf);
        $this->assertSame($tipo, $r->metadata['imagen_tipo']);
        if ($tipo === 'jpg') {
            $this->assertStringContainsString('/Filter /DCTDecode', $r->pdf);
        }
    }

    /** @return array<string,array{0:string}> */
    public static function tiposDeImagen(): array
    {
        return ['png' => ['png'], 'jpg' => ['jpg'], 'gif' => ['gif']];
    }

    public function test_la_extension_jpeg_se_trata_como_jpg(): void
    {
        $ruta = $this->imagen('jpg');
        $r = $this->renderer()->render($this->solicitud($this->plantilla($ruta, ['ext' => 'JPEG'])));

        $this->assertSame('jpg', $r->metadata['imagen_tipo']);
    }

    public function test_el_contenido_usa_las_posiciones_y_fuentes_del_sistema_viejo(): void
    {
        $r = $this->renderer()->render($this->solicitud());

        // Fondo a página completa: 297 x 210 mm = 841.89 x 595.28 pt.
        $this->assertMatchesRegularExpression('#q 841\.89 0 0 595\.28 0\.00 0\.00 cm /I1 Do Q#', $r->pdf);
        // Tres textos con las tres fuentes: 26, 14 y 6 pt, y el texto exacto (sin compresión, en claro).
        $this->assertSame(1, preg_match_all('#/F1 26\.00 Tf#', $r->pdf));
        $this->assertSame(1, preg_match_all('#/F1 14\.00 Tf#', $r->pdf));
        $this->assertSame(1, preg_match_all('#/F1 6\.00 Tf#', $r->pdf));
        $this->assertStringContainsString('(PERSONA DE PRUEBA) Tj', $r->pdf);
        $this->assertStringContainsString('(CC: 12.345.678) Tj', $r->pdf);
        $this->assertStringContainsString('(5237) Tj', $r->pdf);
        // El objeto de contenido de la página (4 0 obj) no lleva /Filter: va en claro.
        $this->assertMatchesRegularExpression("#\n4 0 obj\n<</Length \\d+>>\nstream\n#", $r->pdf, 'El contenido no se comprime');
    }

    public function test_el_texto_va_centrado_y_el_codigo_a_la_derecha(): void
    {
        $r = $this->renderer()->render($this->solicitud());
        preg_match_all('#BT ([\d.]+) ([\d.]+) Td \((.*?)\) Tj ET#', $r->pdf, $m, PREG_SET_ORDER);
        $this->assertCount(3, $m);

        // El nombre y el documento están centrados en 297 mm (la x cambia con el ancho del texto, la y es la del diseño).
        [$nombre, $doc, $codigo] = $m;
        $this->assertSame('PERSONA DE PRUEBA', $nombre[3]);
        // Línea base de FPDF: y + h/2 + 0,3·tamaño (en mm), medida desde arriba; el PDF cuenta desde abajo.
        $base = fn (float $yMm, float $hMm, int $pt) => 595.28 - ($yMm + $hMm / 2 + 0.3 * $pt * 25.4 / 72) * 72 / 25.4;
        $this->assertEqualsWithDelta($base(90, 1, 26), (float) $nombre[2], 0.05, 'nombre: tras Ln(80) desde el margen de 10 mm');
        $this->assertEqualsWithDelta($base(100, 10, 14), (float) $doc[2], 0.05, 'documento: tras Ln(10)');
        $this->assertEqualsWithDelta($base(179, 10, 6), (float) $codigo[2], 0.05, 'código: tras Ln(79)');
        // Centrados: el centro del texto cae en el centro de la página (420.945 pt).
        $this->assertEqualsWithDelta(420.945, (float) $nombre[1] + $this->anchoAprox('PERSONA DE PRUEBA', 26) / 2, 0.1);
        $this->assertEqualsWithDelta(420.945, (float) $doc[1] + $this->anchoAprox('CC: 12.345.678', 14) / 2, 0.1);
        // El código termina en el margen derecho: (297 - 10) mm = 813.54 pt menos el ancho del texto.
        $this->assertGreaterThan((float) $doc[1], (float) $codigo[1]);
        $this->assertLessThan(813.54, (float) $codigo[1]);
    }

    // ── Determinismo ──────────────────────────────────────────────────────────

    public function test_mismo_input_mismos_bytes_y_mismo_sha256(): void
    {
        $ruta = $this->imagen('png');
        $p = $this->plantilla($ruta);

        $a = $this->renderer()->render($this->solicitud($p));
        usleep(1_100_000); // el reloj no interviene: pasa más de un segundo entre renders
        $b = $this->renderer()->render($this->solicitud($p));

        $this->assertSame($a->pdf, $b->pdf);
        $this->assertSame($a->sha256, $b->sha256);
        $this->assertSame($a->metadata, $b->metadata);
    }

    public function test_el_render_repetido_en_el_mismo_proceso_no_acumula_estado(): void
    {
        $p = $this->plantilla();
        $hashes = [];
        for ($i = 0; $i < 5; $i++) {
            $hashes[] = $this->renderer()->render($this->solicitud($p))->sha256;
        }
        $this->assertCount(1, array_unique($hashes));
    }

    public function test_sin_metadatos_variables_la_fecha_es_la_del_snapshot_y_no_hay_compresion(): void
    {
        $r = $this->renderer()->render($this->solicitud());

        $this->assertStringContainsString('/CreationDate (D:20261005134802)', $r->pdf);
        $this->assertStringContainsString('/Producer (FPDF 1.81)', $r->pdf);
        $this->assertSame('D:20261005134802', $r->metadata['creation_date']);
        $this->assertFalse($r->metadata['compresion']);
        // El contenido de la página es texto en claro: no hay streams de contenido comprimidos.
        $this->assertStringContainsString("\nBT ", $r->pdf);
        $this->assertStringNotContainsString('/ModDate', $r->pdf);
    }

    public function test_otra_fecha_de_snapshot_cambia_solo_la_fecha_del_pdf(): void
    {
        $p = $this->plantilla();
        $a = $this->renderer()->render($this->solicitud($p));
        $b = $this->renderer()->render($this->solicitud($p, ['fecha' => new DateTimeImmutable('2027-01-02 03:04:05', new \DateTimeZone('UTC'))]));

        $this->assertNotSame($a->sha256, $b->sha256);
        $this->assertStringContainsString('D:20270102030405', $b->pdf);
        $this->assertSame(strlen($a->pdf), strlen($b->pdf));
        $this->assertSame(str_replace('D:20261005134802', 'D:20270102030405', $a->pdf), $b->pdf, 'Solo difiere la CreationDate');
    }

    public function test_la_zona_horaria_del_proceso_no_cambia_el_resultado(): void
    {
        $p = $this->plantilla();
        $antes = date_default_timezone_get();
        try {
            date_default_timezone_set('America/Bogota');
            $a = $this->renderer()->render($this->solicitud($p));
            date_default_timezone_set('Asia/Tokyo');
            $b = $this->renderer()->render($this->solicitud($p));
        } finally {
            date_default_timezone_set($antes);
        }
        $this->assertSame($a->sha256, $b->sha256);
    }

    // ── Casos que el renderer se niega a generar ─────────────────────────────

    public function test_se_niega_si_la_plantilla_esta_faltante(): void
    {
        $this->negado($this->solicitud($this->plantilla($this->dir.'/no_existe.png', ['estado' => 'faltante', 'sha' => null])), RenderNoPermitido::PLANTILLA_FALTANTE);
    }

    public function test_se_niega_con_extension_invalida(): void
    {
        $this->negado($this->solicitud($this->plantilla(null, ['estado' => 'extension_invalida', 'ext' => '7 cuipo'])), RenderNoPermitido::EXTENSION_INVALIDA);
        $this->negado($this->solicitud($this->plantilla(null, ['ext' => null])), RenderNoPermitido::EXTENSION_INVALIDA);
        $this->negado($this->solicitud($this->plantilla(null, ['ext' => ''])), RenderNoPermitido::EXTENSION_INVALIDA);
    }

    public function test_se_niega_si_fpdf_no_soporta_el_tipo_o_el_catalogo_dice_que_no_es_renderizable(): void
    {
        $this->negado($this->solicitud($this->plantilla(null, ['ext' => 'webp'])), RenderNoPermitido::IMAGEN_NO_SOPORTADA);
        $this->negado($this->solicitud($this->plantilla(null, ['ext' => 'bmp'])), RenderNoPermitido::IMAGEN_NO_SOPORTADA);
        $this->negado($this->solicitud($this->plantilla(null, ['renderizable' => false])), RenderNoPermitido::IMAGEN_NO_SOPORTADA);
    }

    #[DataProvider('estadosNoRenderizables')]
    public function test_se_niega_con_estados_que_no_son_ok(string $estado): void
    {
        $this->negado($this->solicitud($this->plantilla(null, ['estado' => $estado])), RenderNoPermitido::PLANTILLA_NO_OK);
    }

    /** @return array<string,array{0:string}> */
    public static function estadosNoRenderizables(): array
    {
        return ['huerfana' => ['huerfana'], 'candidata_revision' => ['candidata_revision']];
    }

    public function test_se_niega_si_el_archivo_no_esta_o_su_sha_no_coincide(): void
    {
        $this->negado($this->solicitud($this->plantilla($this->dir.'/no_existe.png', ['sha' => str_repeat('a', 64)])), RenderNoPermitido::ARCHIVO_AUSENTE);
        $this->negado($this->solicitud($this->plantilla(null, ['sha' => str_repeat('0', 64)])), RenderNoPermitido::SHA_NO_COINCIDE);
        $this->negado($this->solicitud($this->plantilla(null, ['sha' => null])), RenderNoPermitido::SHA_NO_COINCIDE);
    }

    public function test_un_png_que_fpdf_no_puede_cargar_se_niega_sin_corregirlo(): void
    {
        $ruta = $this->dir.'/corrupta.png';
        file_put_contents($ruta, 'esto no es un png');
        $this->negado($this->solicitud($this->plantilla($ruta)), RenderNoPermitido::ERROR_FPDF);
    }

    #[DataProvider('documentosEnRevision')]
    public function test_se_niega_con_documentos_en_revision(?string $documento): void
    {
        $this->negado($this->solicitud(null, ['doc' => $documento]), $documento === null || trim($documento) === '' ? RenderNoPermitido::DATOS_FALTANTES : RenderNoPermitido::DOCUMENTO_REVISION);
    }

    /** @return array<string,array{0:?string}> */
    public static function documentosEnRevision(): array
    {
        return [
            'letras' => ['ABC123'],
            'separadores' => ['73.156.827'],
            'ceros a la izquierda' => ['009876543'],
            'NBSP inicial' => ["\u{00A0}73156827"],
            'espacio en medio que cambia lo impreso' => ['123 456'],
            'demasiado largo' => ['1234567890123456'],
            'vacío' => [''],
            'solo espacios' => ['   '],
            'nulo' => [null],
        ];
    }

    public function test_un_documento_valido_con_un_salto_de_linea_al_final_se_imprime_igual_que_antes(): void
    {
        $r = $this->renderer()->render($this->solicitud(null, ['doc' => "73156827\n"]));

        $this->assertStringContainsString('(CC: 73.156.827) Tj', $r->pdf);
    }

    #[DataProvider('datosObligatorios')]
    public function test_se_niega_si_faltan_datos_obligatorios(array $cambio): void
    {
        $this->negado($this->solicitud(null, $cambio), RenderNoPermitido::DATOS_FALTANTES);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function datosObligatorios(): array
    {
        return [
            'nombre vacío' => [['nombre' => '   ']],
            'sin código' => [['codigo' => null]],
            'código no numérico' => [['codigo' => '52A7']],
            'código vacío' => [['codigo' => '']],
        ];
    }

    // ── tipo_documento vacío: fidelidad histórica (tipo.': '.número) ──────────

    #[DataProvider('tiposVacios')]
    public function test_un_tipo_de_documento_vacio_no_bloquea_y_reproduce_el_texto_historico(?string $tipo, string $esperado): void
    {
        $r = $this->renderer()->render($this->solicitud(null, ['tipo' => $tipo, 'doc' => '1234567']));

        // Literal del sistema viejo: nada de rellenar «CC», inventar un tipo ni ocultar los dos puntos.
        $this->assertStringContainsString("($esperado) Tj", $r->pdf);
        $this->assertSame(['TIPO_DOCUMENTO_VACIO'], $r->metadata['advertencias']);
        $this->assertSame(1, $r->metadata['paginas']);
    }

    /** @return array<string,array{0:?string,1:string}> */
    public static function tiposVacios(): array
    {
        return ['nulo' => [null, ': 1.234.567'], 'cadena vacía' => ['', ': 1.234.567'], 'solo espacios (literal)' => ['  ', '  : 1.234.567']];
    }

    public function test_el_tipo_vacio_es_determinista_y_sin_tipo_no_hay_advertencia(): void
    {
        $p = $this->plantilla();
        $a = $this->renderer()->render($this->solicitud($p, ['tipo' => '']));
        $b = $this->renderer()->render($this->solicitud($p, ['tipo' => '']));
        $con = $this->renderer()->render($this->solicitud($p, ['tipo' => 'CC']));

        $this->assertSame($a->sha256, $b->sha256);
        $this->assertSame($a->pdf, $b->pdf);
        $this->assertNotSame($a->sha256, $con->sha256);
        $this->assertSame([], $con->metadata['advertencias']);
        $this->assertStringContainsString('(CC: 12.345.678) Tj', $con->pdf);
        $this->assertStringContainsString('(: 12.345.678) Tj', $a->pdf);
    }

    public function test_tipo_vacio_con_documento_en_revision_sigue_bloqueado(): void
    {
        foreach (['ABC123', '009876543', '73.156.827', '123 456', "\u{00A0}73156827", '1234567890123456'] as $doc) {
            $this->negado($this->solicitud(null, ['tipo' => null, 'doc' => $doc]), RenderNoPermitido::DOCUMENTO_REVISION);
            $this->negado($this->solicitud(null, ['tipo' => '', 'doc' => $doc]), RenderNoPermitido::DOCUMENTO_REVISION);
        }
        $this->negado($this->solicitud(null, ['tipo' => null, 'doc' => '']), RenderNoPermitido::DATOS_FALTANTES);
    }

    public function test_tipo_vacio_sigue_exigiendo_plantilla_valida_y_demas_datos(): void
    {
        $this->negado($this->solicitud($this->plantilla(null, ['estado' => 'faltante', 'sha' => null]), ['tipo' => null]), RenderNoPermitido::PLANTILLA_FALTANTE);
        $this->negado($this->solicitud(null, ['tipo' => null, 'codigo' => null]), RenderNoPermitido::DATOS_FALTANTES);
        $this->negado($this->solicitud(null, ['tipo' => null, 'nombre' => ' ']), RenderNoPermitido::DATOS_FALTANTES);
    }

    public function test_los_mensajes_de_negativa_no_incluyen_datos_personales(): void
    {
        foreach ([['nombre' => 'MARIA SECRETA PEREZ', 'doc' => 'ABC123SECRETO'], ['nombre' => 'MARIA SECRETA PEREZ', 'codigo' => 'xx']] as $c) {
            try {
                $this->renderer()->render($this->solicitud(null, $c));
                $this->fail('Debía negarse');
            } catch (RenderNoPermitido $e) {
                $this->assertStringNotContainsString('SECRETA', $e->getMessage());
                $this->assertStringNotContainsString('SECRETO', $e->getMessage());
            }
        }
    }

    // ── Encoding histórico ────────────────────────────────────────────────────

    public function test_los_acentos_representables_pasan_como_iso_8859_1_sin_advertencias(): void
    {
        $r = $this->renderer()->render($this->solicitud(null, ['nombre' => 'JOSÉ MUÑOZ ÁLVAREZ Ü']));

        $this->assertStringContainsString("(JOS\xC9 MU\xD1OZ \xC1LVAREZ \xDC) Tj", $r->pdf);
        $this->assertSame([], $r->metadata['caracteres_no_representables']);
        $this->assertSame(0, $r->metadata['secuencias_utf8_invalidas']);
    }

    public function test_un_caracter_no_representable_sale_como_interrogacion_igual_que_antes_pero_queda_reportado(): void
    {
        $r = $this->renderer()->render($this->solicitud(null, ['nombre' => 'ŁUKASZ – “X” €']));

        $this->assertStringContainsString('(?UKASZ ? ?X? ?) Tj', $r->pdf);
        $this->assertSame(['U+0141', 'U+2013', 'U+201C', 'U+201D', 'U+20AC'], $r->metadata['caracteres_no_representables']);
    }

    public function test_utf8_invalido_se_reproduce_como_interrogacion_y_se_cuenta(): void
    {
        $r = $this->renderer()->render($this->solicitud(null, ['nombre' => "AB\xFFC\xC3"]));

        $this->assertStringContainsString('(AB?C?) Tj', $r->pdf);
        $this->assertSame(2, $r->metadata['secuencias_utf8_invalidas']);
    }

    public function test_un_nombre_muy_largo_se_renderiza_en_una_pagina_como_en_el_sistema_viejo(): void
    {
        $r = $this->renderer()->render($this->solicitud(null, ['nombre' => str_repeat('NOMBRE MUY LARGO ', 12)]));

        $this->assertSame(1, $r->metadata['paginas']);
        $this->assertSame(1, preg_match_all('#/Type /Page\b#', $r->pdf));
    }

    // ── Aislamiento ───────────────────────────────────────────────────────────

    public function test_se_carga_exactamente_fpdf_1_81_desde_su_carpeta_aislada_y_con_su_sha(): void
    {
        Fpdf181::cargar();

        $this->assertSame('1.81', FPDF_VERSION);
        $this->assertSame(realpath(Fpdf181::rutaFpdf()), realpath((new \ReflectionClass(\FPDF::class))->getFileName()));
        $this->assertSame(Fpdf181::SHA256, hash_file('sha256', Fpdf181::rutaFpdf()));
        $this->assertStringStartsWith(str_replace('/', DIRECTORY_SEPARATOR, resource_path('legado')), Fpdf181::directorio());
        $this->assertTrue(function_exists('get_magic_quotes_runtime'));
        $this->assertFalse(get_magic_quotes_runtime());
    }

    public function test_no_se_toca_ni_se_mezcla_con_tcpdf_ni_fpdi(): void
    {
        Fpdf181::cargar();

        $this->assertNotSame('', (string) (new \ReflectionClass(\FPDF::class))->getFileName());
        $this->assertFalse(is_subclass_of(\FPDF::class, \TCPDF::class));
        $this->assertFalse(class_exists(\FPDF::class, false) && is_a(\FPDF::class, \TCPDF::class, true));
    }
}
