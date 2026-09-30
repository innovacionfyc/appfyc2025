<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Generacion\DatosCredencial;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException as E;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use App\Support\CredentialFlow\Generacion\PlanificadorTexto;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;
use Tests\Feature\CredentialFlow\Support\PdfBase;

/** F&C Credential Flow · Fase 5: PDF generado (estructura, geometría y endpoint QA). */
class GeneracionPdfTest extends CredentialFlowTestCase
{
    private const NOMBRES_PS = [300 => 'Outfit-Light', 400 => 'Outfit-Regular', 500 => 'Outfit-Medium', 600 => 'Outfit-SemiBold', 700 => 'Outfit-Bold', 800 => 'Outfit-ExtraBold'];

    private int $n = 0;

    private function el(array $c = []): array
    {
        $this->n++;

        return array_merge([
            'id' => sprintf('00000000-0000-4000-8000-%012d', $this->n), 'type' => 'text', 'field' => null, 'text' => 'Texto',
            'x' => 96, 'y' => 200, 'width' => 600, 'height' => 40, 'fontFamily' => 'outfit', 'fontSize' => 22,
            'fontWeight' => 700, 'color' => '#932833', 'align' => 'center',
        ], $c);
    }

    /** Crea una plantilla por el flujo real, con un PDF base real de w × h y el diseño dado. */
    private function plantillaConDiseno(array $elementos, float $ancho = 792, float $alto = 612, ?array $pagina = null, int $rotate = 0): Plantilla
    {
        $p = $this->crearPlantilla();
        Storage::disk('local')->put($p->rutaPdfEsperada(), PdfBase::crear($ancho, $alto, $rotate));
        $p->update(['diseno' => ['page' => $pagina ?? ['width' => $ancho, 'height' => $alto], 'elements' => $elementos]]);

        return $p->fresh();
    }

    private function generar(Plantilla $p): string
    {
        return GeneradorCredencialPdf::generar($p, DatosCredencial::qa());
    }

    private function assertCodigo(string $codigo, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Se esperaba {$codigo}");
        } catch (E $e) {
            $this->assertSame($codigo, $e->codigo);
        }
    }

    // ── Estructura ────────────────────────────────────────────────────────────

    public function test_pdf_valido_una_pagina_mediabox_y_plantilla_como_xobject_vectorial(): void
    {
        $p = $this->plantillaConDiseno([$this->el(['text' => 'Hola'])]);
        $pdf = new InspectorPdf($this->generar($p));

        $this->assertTrue($pdf->firmaValida());
        $this->assertSame(1, $pdf->paginas());
        $this->assertEqualsWithDelta(792, $pdf->mediaBox()[0], 0.001);
        $this->assertEqualsWithDelta(612, $pdf->mediaBox()[1], 0.001);

        $xobj = $pdf->xobjetos();
        $this->assertCount(1, $xobj);
        $this->assertSame('Form', $xobj[0]['subtype']); // la plantilla queda importada, no rasterizada
        $this->assertStringNotContainsString('/Subtype /Image', $pdf->bytes);
    }

    public static function formatos(): array
    {
        return [
            'horizontal 792×612' => [792, 612],
            'vertical 612×792' => [612, 792],
            'cuadrado 600×600' => [600, 600],
            'A4 vertical 595.28×841.89' => [595.28, 841.89],
            'apaisado 841.89×595.28' => [841.89, 595.28],
        ];
    }

    #[DataProvider('formatos')]
    public function test_mediabox_es_ancho_por_alto_del_diseno(float $ancho, float $alto): void
    {
        $p = $this->plantillaConDiseno([], $ancho, $alto);
        $pdf = new InspectorPdf($this->generar($p));

        // TCPDF 6.11.4: AddPage(orientación, [ancho, alto]) con la orientación explícita (L si
        // ancho ≥ alto, P si no) produce exactamente ancho × alto; el orden del array no se intercambia.
        $this->assertEqualsWithDelta($ancho, $pdf->mediaBox()[0], 0.01);
        $this->assertEqualsWithDelta($alto, $pdf->mediaBox()[1], 0.01);
    }

    public function test_outfit_va_incrustada_como_subconjunto_truetype(): void
    {
        $elementos = [];
        foreach (self::NOMBRES_PS as $peso => $nombre) {
            $elementos[] = $this->el(['text' => "Peso $peso", 'fontWeight' => $peso, 'y' => 20 + $peso / 2]);
        }
        $bytes = $this->generar($this->plantillaConDiseno($elementos));
        $fuentes = (new InspectorPdf($bytes))->fuentes();

        $this->assertCount(6, $fuentes);
        foreach ($fuentes as $f) {
            $this->assertTrue($f['subconjunto'], $f['baseFont'].' debería ser un subconjunto (AAAAAA+…)');
            $this->assertTrue($f['fontFile2'], $f['baseFont'].' debería incrustar FontFile2');
        }
        $nombres = array_map(fn ($f) => substr($f['baseFont'], 7), $fuentes);
        sort($nombres);
        $esperados = array_values(self::NOMBRES_PS);
        sort($esperados);
        $this->assertSame($esperados, $nombres);
    }

    // ── Geometría: plan ↔ PDF ─────────────────────────────────────────────────

    public function test_textos_del_pdf_coinciden_con_el_plan_en_x_baseline_tamano_y_peso(): void
    {
        $elementos = [
            $this->el(['field' => 'nombre_completo', 'text' => '', 'align' => 'center', 'y' => 100, 'fontWeight' => 800, 'fontSize' => 28]),
            $this->el(['field' => 'evento', 'text' => '', 'align' => 'right', 'y' => 160.37, 'x' => 300, 'width' => 460, 'fontWeight' => 600]),
            $this->el(['field' => 'documento', 'text' => '', 'align' => 'left', 'y' => 220.61, 'fontWeight' => 500, 'fontSize' => 19]),
            $this->el(['text' => 'Izquierda 22 pt', 'align' => 'left', 'y' => 280, 'fontWeight' => 300]),
            $this->el(['text' => 'Centro 40 pt', 'align' => 'center', 'y' => 320.5, 'height' => 60, 'fontSize' => 40, 'fontWeight' => 400]),
            $this->el(['text' => 'Derecha ÁÉÍÓÚ ñ', 'align' => 'right', 'y' => 400.25]),
            $this->el(['text' => "LÍNEA UNO\nLÍNEA DOS\nTRES", 'align' => 'center', 'y' => 460, 'height' => 100, 'fontSize' => 22]),
            $this->el(['text' => "Izq A\nIzq BB", 'align' => 'left', 'y' => 30, 'height' => 60, 'x' => 50, 'width' => 200, 'fontWeight' => 700]),
        ];
        $p = $this->plantillaConDiseno($elementos);
        $planes = PlanificadorTexto::planificar($p->diseno, DatosCredencial::qa(), ['width' => 792.0, 'height' => 612.0]);
        $textos = (new InspectorPdf($this->generar($p)))->textos();

        $esperadas = [];
        foreach ($planes as $plan) {
            foreach ($plan->lineas as $l) {
                $esperadas[] = [$plan, $l];
            }
        }
        $this->assertCount(count($esperadas), $textos);

        // El generador pinta los elementos en el orden del diseño y las líneas de arriba abajo.
        foreach ($esperadas as $i => [$plan, $l]) {
            $t = $textos[$i];
            $this->assertSame($l['texto'], $t['texto'], "línea $i texto");
            $this->assertEqualsWithDelta($plan->fontSizeEfectivo, $t['size'], 0.0005, "línea $i tamaño");
            $this->assertSame(self::NOMBRES_PS[$plan->fontWeight], substr($t['baseFont'], 7), "línea $i peso");
            $this->assertEqualsWithDelta($l['xInicio'], $t['x'], 0.01, "línea $i x ({$plan->align})");
            $this->assertEqualsWithDelta($l['baseline'], $t['baseline'], 0.01, "línea $i baseline");
        }
    }

    public function test_alineacion_left_center_right_en_el_contenido_del_pdf(): void
    {
        $p = $this->plantillaConDiseno([
            $this->el(['text' => 'Alineado', 'align' => 'left', 'y' => 50]),
            $this->el(['text' => 'Alineado', 'align' => 'center', 'y' => 100]),
            $this->el(['text' => 'Alineado', 'align' => 'right', 'y' => 150]),
        ]);
        $t = (new InspectorPdf($this->generar($p)))->textos();
        $ancho = PlanificadorTexto::planificar($p->diseno, DatosCredencial::qa(), ['width' => 792.0, 'height' => 612.0])[0]->lineas[0]['ancho'];

        $this->assertEqualsWithDelta(96, $t[0]['x'], 0.01);
        $this->assertEqualsWithDelta(96 + (600 - $ancho) / 2, $t[1]['x'], 0.01);
        $this->assertEqualsWithDelta(96 + 600 - $ancho, $t[2]['x'], 0.01);
    }

    public function test_campo_que_se_reduce_se_dibuja_con_el_tamano_efectivo(): void
    {
        $p = $this->plantillaConDiseno([$this->el(['field' => 'nombre_completo', 'text' => '', 'fontSize' => 28, 'width' => 300, 'fontWeight' => 800])]);
        $t = (new InspectorPdf($this->generar($p)))->textos();

        $this->assertCount(1, $t);
        $this->assertEqualsWithDelta(21.0, $t[0]['size'], 0.0005);
        $this->assertSame('JUAN CARLOS PÉREZ GÓMEZ', $t[0]['texto']);
    }

    public function test_el_color_del_texto_llega_al_pdf(): void
    {
        $bytes = $this->generar($this->plantillaConDiseno([$this->el(['text' => 'Color', 'color' => '#932833'])]));

        // #932833 → 0.576471 0.156863 0.200000 rg
        $this->assertStringContainsString('0.576471 0.156863 0.200000 rg', $this->contenidoDePagina($bytes));
    }

    private function contenidoDePagina(string $bytes): string
    {
        $r = new \ReflectionMethod(InspectorPdf::class, 'contenidoPagina');

        return $r->invoke(new InspectorPdf($bytes));
    }

    // ── Sin persistencia ni temporales ────────────────────────────────────────

    public function test_no_se_escribe_ningun_archivo_ni_temporal(): void
    {
        $p = $this->plantillaConDiseno([$this->el(['text' => 'Hola'])]);
        $antesDisco = Storage::disk('local')->allFiles();
        $temporales = fn () => glob(rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'__tcpdf_*') ?: [];
        $antesTmp = $temporales();

        $this->generar($p);

        $this->assertSame($antesDisco, Storage::disk('local')->allFiles());
        $this->assertSame($antesTmp, $temporales());
    }

    public function test_el_pdf_no_pesa_mucho_mas_que_la_plantilla(): void
    {
        $base = strlen(PdfBase::crear());
        $bytes = $this->generar($this->plantillaConDiseno([$this->el(['text' => 'Hola']), $this->el(['text' => 'Adiós', 'fontWeight' => 300, 'y' => 300])]));

        $this->assertLessThan($base + 60_000, strlen($bytes)); // subconjuntos de fuente, no fuentes completas
    }

    // ── /Rotate de la página base ─────────────────────────────────────────────
    // FPDI 2.6.8 normaliza la rotación: getTemplateSize devuelve el tamaño ROTADO (90/270 intercambian
    // ancho y alto; 180 no) y la plantilla se importa como un Form con /Matrix que la deja como la
    // muestra un visor. El diseño (editor/pdfjs) también usa el tamaño rotado, así que todo coincide.

    public static function rotaciones(): array
    {
        // rotación => [ancho visible, alto visible, marca roja visible (x0, y0, x1, y1) medida con pdfjs, matrix esperada]
        return [
            'Rotate 0' => [0, 792, 612, [30, 30, 150, 90], null],
            'Rotate 90' => [90, 612, 792, [522, 30, 582, 150], [0, -1, 1, 0, 0, 792]],
            'Rotate 180' => [180, 792, 612, [642, 522, 762, 582], [-1, 0, 0, -1, 792, 612]],
            'Rotate 270' => [270, 612, 792, [30, 642, 90, 762], [0, 1, -1, 0, 612, 0]],
        ];
    }

    #[DataProvider('rotaciones')]
    public function test_pdf_base_con_rotate_se_genera_con_la_orientacion_y_geometria_del_visor(int $rotate, float $ancho, float $alto, array $marca, ?array $matrix): void
    {
        $p = $this->plantillaConDiseno([$this->el(['text' => 'MARCA', 'x' => 200, 'y' => 100, 'width' => 300, 'height' => 40, 'align' => 'left', 'fontSize' => 28])], 792, 612, ['width' => $ancho, 'height' => $alto], $rotate);
        $pdf = new InspectorPdf($this->generar($p));

        // Página de salida = tamaño visible (rotado)
        $this->assertEqualsWithDelta($ancho, $pdf->mediaBox()[0], 0.01);
        $this->assertEqualsWithDelta($alto, $pdf->mediaBox()[1], 0.01);

        // Texto: mismas coordenadas de diseño (sistema visible), sin compensaciones
        $plan = PlanificadorTexto::planificar($p->diseno, DatosCredencial::qa(), ['width' => $ancho, 'height' => $alto])[0];
        $t = $pdf->textos();
        $this->assertCount(1, $t);
        $this->assertEqualsWithDelta($plan->lineas[0]['xInicio'], $t[0]['x'], 0.01);
        $this->assertEqualsWithDelta($plan->lineas[0]['baseline'], $t[0]['baseline'], 0.01);

        // Plantilla: la /Matrix de FPDI deja la marca roja (arriba a la izquierda en el PDF sin rotar) donde la muestra pdfjs.
        $plantilla = $pdf->plantilla();
        if ($matrix === null) {
            $this->assertNull($plantilla['matrix']);
        } else {
            $this->assertEqualsWithDelta($matrix, $plantilla['matrix'], 1e-9);
        }
        preg_match('/1\.000000 0\.000000 0\.000000 rg\s+([\d.\-]+) ([\d.\-]+) ([\d.\-]+) ([\d.\-]+) re/', $plantilla['contenido'], $r);
        [$m0, $m1, $m2, $m3, $m4, $m5] = $plantilla['matrix'] ?? [1, 0, 0, 1, 0, 0];
        $xs = $ys = [];
        foreach ([[$r[1], $r[2]], [$r[1] + $r[3], $r[2] + $r[4]]] as [$x, $y]) {
            $xs[] = $m0 * $x + $m2 * $y + $m4;
            $ys[] = $alto - ($m1 * $x + $m3 * $y + $m5); // arriba = 0
        }
        $visible = [min($xs), min($ys), max($xs), max($ys)];
        foreach ($marca as $i => $esperado) {
            $this->assertEqualsWithDelta($esperado, $visible[$i], 0.01, "marca roja, coordenada $i con Rotate $rotate");
        }
    }

    public static function rotaciones90y270(): array
    {
        return ['Rotate 90' => [90], 'Rotate 270' => [270]];
    }

    #[DataProvider('rotaciones90y270')]
    public function test_un_diseno_guardado_con_el_tamano_sin_rotar_no_coincide_con_un_pdf_rotado(int $rotate): void
    {
        // El editor mide la página ya rotada (612 × 792); un diseño de 792 × 612 es de otra orientación.
        $p = $this->plantillaConDiseno([], 792, 612, ['width' => 792, 'height' => 612], $rotate);

        $this->assertCodigo(E::PAGINA_DISTINTA, fn () => $this->generar($p));
    }

    public function test_el_endpoint_acepta_pdf_rotado_con_el_tamano_visible(): void
    {
        $p = $this->plantillaConDiseno([$this->el(['text' => 'Hola', 'x' => 20, 'width' => 500])], 792, 612, ['width' => 612, 'height' => 792], 90);

        $r = $this->pedir($p);

        $r->assertOk();
        $this->assertEqualsWithDelta(612, (new InspectorPdf($r->getContent()))->mediaBox()[0], 0.01);
    }

    // ── Errores ───────────────────────────────────────────────────────────────

    public function test_pdf_ilegible_para_fpdi(): void
    {
        $p = $this->crearPlantilla(); // el PDF de los tests de la Fase 1 no tiene tabla xref: FPDI no puede importarlo
        $p->update(['diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => []]]);

        $this->assertCodigo(E::PDF_ILEGIBLE, fn () => $this->generar($p->fresh()));
    }

    public function test_pdf_base_de_otro_tamano(): void
    {
        $p = $this->plantillaConDiseno([], 612, 792, ['width' => 792, 'height' => 612]);

        $this->assertCodigo(E::PAGINA_DISTINTA, fn () => $this->generar($p));
    }

    public function test_sin_pdf_sin_diseno_y_ruta_registrada_distinta(): void
    {
        $p = $this->crearPlantilla();
        $this->assertCodigo(E::SIN_DISENO, fn () => $this->generar($p));

        $p->update(['diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => []]]);
        Storage::disk('local')->delete($p->rutaPdfEsperada());
        $this->assertCodigo(E::PLANTILLA_SIN_PDF, fn () => $this->generar($p->fresh()));

        $p->update(['archivo_pdf' => 'credential-flow/plantillas/999/base.pdf']);
        $this->assertCodigo(E::PLANTILLA_SIN_PDF, fn () => $this->generar($p->fresh()));
    }

    // ── Endpoint ──────────────────────────────────────────────────────────────

    private function pedir(Plantilla $p, $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->admin())->get(route('credential-flow.plantillas.pdf-prueba', $p));
    }

    public function test_admin_descarga_el_pdf_con_las_cabeceras_correctas(): void
    {
        $p = $this->plantillaConDiseno([$this->el(['field' => 'nombre_completo', 'text' => '']), $this->el(['text' => 'Fijo', 'y' => 300])]);
        $movimientos = Movimiento::count();

        $r = $this->pedir($p);

        $r->assertOk();
        $r->assertHeader('Content-Type', 'application/pdf');
        $r->assertHeader('Content-Disposition', 'attachment; filename="credencial-prueba-'.$p->id.'.pdf"');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $r->headers->get('Cache-Control'));
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(['JUAN CARLOS PÉREZ GÓMEZ', 'Fijo'], array_column((new InspectorPdf($r->getContent()))->textos(), 'texto'));
        $this->assertSame($movimientos, Movimiento::count(), 'El PDF de prueba no registra Movimiento');
        $this->assertStringNotContainsString('JUAN', $r->headers->get('Content-Disposition'));
    }

    public function test_permisos_del_endpoint(): void
    {
        $p = $this->plantillaConDiseno([]);

        $this->pedir($p, $this->superAdmin())->assertOk();
        $this->pedir($p, $this->comercial())->assertForbidden();
    }

    public function test_invitado_es_redirigido_al_login(): void
    {
        $p = $this->plantillaConDiseno([]);
        $this->app['auth']->forgetGuards(); // crearPlantilla dejó una sesión de admin iniciada

        $this->get(route('credential-flow.plantillas.pdf-prueba', $p))->assertRedirect(route('login'));
    }

    public function test_el_endpoint_esta_limitado_por_throttle(): void
    {
        $p = $this->plantillaConDiseno([]);
        $admin = $this->admin();

        for ($i = 0; $i < 6; $i++) {
            $this->pedir($p, $admin)->assertOk();
        }
        $this->pedir($p, $admin)->assertStatus(429);
    }

    public function test_la_ruta_esta_protegida_y_no_hay_ruta_publica(): void
    {
        $ruta = app('router')->getRoutes()->getByName('credential-flow.plantillas.pdf-prueba');
        $mw = $ruta->gatherMiddleware();

        $this->assertContains('auth', $mw);
        $this->assertContains('rol:super-admin,admin', $mw);
        $this->assertContains('throttle:6,1', $mw);
        $this->assertSame('admin/credential-flow/plantillas/{plantilla}/pdf-prueba', $ruta->uri());
    }

    public function test_plantilla_inexistente_es_404(): void
    {
        $this->actingAs($this->admin())->get('/admin/credential-flow/plantillas/9999/pdf-prueba')->assertNotFound();
    }

    public static function fallos(): array
    {
        return [
            'fuente heredada' => [['fontFamily' => 'Figtree'], E::FUENTE_NO_REPRODUCIBLE],
            'carácter no soportado' => [['text' => 'Hola 日本'], E::CARACTER_NO_SOPORTADO],
            'no cabe' => [['field' => 'evento', 'text' => '', 'width' => 100], E::NO_CABE],
            'fuera de página' => [['x' => 700, 'width' => 300], E::FUERA_DE_PAGINA],
        ];
    }

    #[DataProvider('fallos')]
    public function test_errores_esperados_son_422_json_sin_datos_internos(array $cambios, string $codigo): void
    {
        $p = $this->plantillaConDiseno([$this->el($cambios)]);

        $r = $this->pedir($p);

        $r->assertStatus(422)->assertJsonPath('error.code', $codigo);
        $mensaje = $r->json('error.message');
        $this->assertNotSame('', $mensaje);
        foreach ([base_path(), storage_path(), 'Stack trace', '.php'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $r->getContent());
        }
    }

    public function test_sin_diseno_sin_pdf_y_pdf_ilegible_por_http(): void
    {
        $sinDiseno = $this->crearPlantilla();
        $this->pedir($sinDiseno)->assertStatus(422)->assertJsonPath('error.code', E::SIN_DISENO);

        $ilegible = $this->crearPlantilla();
        $ilegible->update(['diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => []]]);
        $this->pedir($ilegible)->assertStatus(422)->assertJsonPath('error.code', E::PDF_ILEGIBLE);

        $sinPdf = $this->plantillaConDiseno([]);
        Storage::disk('local')->delete($sinPdf->rutaPdfEsperada());
        $this->pedir($sinPdf)->assertStatus(422)->assertJsonPath('error.code', E::PLANTILLA_SIN_PDF);
    }
}
