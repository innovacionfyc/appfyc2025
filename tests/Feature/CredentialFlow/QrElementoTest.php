<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Emisiones\SnapshotCredencial;
use App\Support\CredentialFlow\Generacion\DatosCredencial;
use App\Support\CredentialFlow\Generacion\DibujanteQr;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException as E;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use App\Support\CredentialFlow\Generacion\PlanificadorQr;
use App\Support\CredentialFlow\Generacion\PlanificadorTexto;
use App\Support\CredentialFlow\Verificacion\UrlVerificacion;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;
use Tests\Feature\CredentialFlow\Support\PdfBase;

/** F&C Credential Flow · Fase 8: elemento QR OPCIONAL (schema 2) en el editor, el generador y las emisiones. */
class QrElementoTest extends EmisionesTestCase
{
    private const PAGINA = ['width' => 792.0, 'height' => 612.0];

    protected function tearDown(): void
    {
        DibujanteQr::$observador = null;

        parent::tearDown();
    }

    private function qr(array $c = []): array
    {
        $this->n++;

        return array_merge([
            'id' => sprintf('00000000-0000-4000-8000-%012d', 9000 + $this->n), 'type' => 'qr', 'x' => 640, 'y' => 460, 'width' => 96, 'height' => 96,
        ], $c);
    }

    private function textos(): array
    {
        return array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 60 + $i * 60]), ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'], range(0, 4));
    }

    /** Plantilla real, con el diseño guardado por el endpoint del editor (así se prueba también schema_version). */
    private function plantillaConQr(array $qr = [], bool $conQr = true): Plantilla
    {
        $p = $this->plantillaLista();
        $elementos = $this->textos();
        if ($conQr) {
            $elementos[] = $this->qr($qr);
        }
        $this->actingAs($this->admin())->putJson(route('credential-flow.plantillas.diseno.update', $p), ['diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => $elementos]])->assertRedirect();

        return $p->fresh();
    }

    private function guardar(Plantilla $p, array $elementos)
    {
        return $this->actingAs($this->admin())->putJson(route('credential-flow.plantillas.diseno.update', $p), ['diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => $elementos]]);
    }

    private function emitirConQr(Plantilla $p): Emision
    {
        $lote = $this->loteCon(1, $p);
        $this->emitirPor($lote, $lote->participantes()->firstOrFail())->assertCreated();

        return Emision::latest('id')->firstOrFail();
    }

    // ── Schema y validación ───────────────────────────────────────────────────

    public function test_sin_qr_el_schema_sigue_en_1_y_con_qr_pasa_a_2(): void
    {
        $sin = $this->plantillaConQr(conQr: false);
        $con = $this->plantillaConQr();

        $this->assertSame(1, $sin->schema_version);
        $this->assertSame(2, $con->schema_version);
        $this->assertSame([1, 2], DisenoSchema::VERSIONES_SOPORTADAS);
        $this->assertTrue(DisenoSchema::soportada(1) && DisenoSchema::soportada(2) && ! DisenoSchema::soportada(3));
        $this->assertSame(1, DisenoSchema::versionPara($sin->diseno));
        $this->assertSame(2, DisenoSchema::versionPara($con->diseno));
    }

    public function test_quitar_el_qr_devuelve_el_schema_a_1_y_guardar_texto_no_migra_a_2(): void
    {
        $p = $this->plantillaConQr();
        $this->assertSame(2, $p->schema_version);

        $this->guardar($p, $this->textos())->assertRedirect();

        $this->assertSame(1, $p->fresh()->schema_version);
    }

    public function test_el_qr_se_guarda_solo_con_sus_claves_y_sin_propiedades_de_texto(): void
    {
        $p = $this->plantillaConQr(['x' => 640.123, 'y' => 460.456, 'width' => 96.004, 'height' => 96.004]);

        $qr = collect($p->diseno['elements'])->firstWhere('type', 'qr');
        $this->assertSame(['id', 'type', 'x', 'y', 'width', 'height'], array_keys($qr));
        $this->assertEquals(640.12, $qr['x']);
        $this->assertEquals(96.0, $qr['width']);
    }

    public function test_un_segundo_qr_se_rechaza(): void
    {
        $p = $this->plantillaLista();

        $this->guardar($p, [$this->qr(), $this->qr(['x' => 400, 'y' => 460])])->assertStatus(422)->assertJsonValidationErrors(['diseno.elements']);
        $this->assertSame(1, $p->fresh()->schema_version);
    }

    public function test_qr_no_cuadrado_muy_pequeno_muy_grande_o_fuera_de_pagina_se_rechazan(): void
    {
        $p = $this->plantillaLista();
        $casos = [
            'no cuadrado' => ['width' => 100, 'height' => 90],
            'diferencia de 0,02 pt' => ['width' => 96.02, 'height' => 96],
            'muy pequeño (84 pt)' => ['width' => 84, 'height' => 84],
            'muy grande (241 pt)' => ['width' => 241, 'height' => 241, 'x' => 100, 'y' => 100],
            'fuera de la página' => ['x' => 720, 'y' => 460],
            'fuera por abajo' => ['x' => 100, 'y' => 560],
        ];
        foreach ($casos as $nombre => $c) {
            $this->guardar($p, [$this->qr($c)])->assertStatus(422, $nombre);
        }
        // Los límites exactos sí valen.
        $this->guardar($p, [$this->qr(['width' => 85, 'height' => 85])])->assertRedirect();
        $this->guardar($p, [$this->qr(['width' => 240, 'height' => 240, 'x' => 100, 'y' => 100])])->assertRedirect();
        $this->guardar($p, [$this->qr(['width' => 96, 'height' => 96.01])])->assertRedirect();
    }

    public function test_el_qr_no_admite_propiedades_de_texto(): void
    {
        $p = $this->plantillaLista();

        foreach (['fontFamily' => 'outfit', 'fontSize' => 22, 'fontWeight' => 700, 'color' => '#000000', 'align' => 'left', 'field' => 'nombre_completo', 'text' => 'hola'] as $clave => $valor) {
            $this->guardar($p, [$this->qr([$clave => $valor])])->assertStatus(422, $clave);
        }
        // Sin ninguna de ellas, es válido; y una clave desconocida sigue rechazada.
        $this->guardar($p, [$this->qr()])->assertRedirect();
        $this->guardar($p, [$this->qr(['relleno' => '#ff0000'])])->assertStatus(422);
    }

    public function test_los_elementos_de_texto_siguen_exigiendo_sus_propiedades(): void
    {
        $p = $this->plantillaLista();
        $texto = $this->elemento(['field' => 'nombre_completo']);
        unset($texto['fontFamily']);

        $this->guardar($p, [$texto])->assertStatus(422)->assertJsonValidationErrors(['diseno.elements.0.fontFamily']);
        $this->guardar($p, [$this->elemento(['type' => 'imagen'])])->assertStatus(422);
    }

    public function test_el_editor_recibe_el_schema_del_qr_y_una_url_de_ejemplo_no_guardada(): void
    {
        $p = $this->plantillaLista();

        $r = $this->actingAs($this->admin())->get(route('credential-flow.plantillas.editor', $p));

        $r->assertOk()->assertInertia(fn ($page) => $page
            ->where('schema.qr.minimo', 85)->where('schema.qr.recomendado', 100)->where('schema.qr.maximo', 240)->where('schema.qr.max', 1)->where('schema.qr.ecc', 'M')->where('schema.qr.quietModulos', 4)
            ->where('schema.versionQr', 2)
            ->where('qrUrlEjemplo', UrlVerificacion::ejemplo()));
        $this->assertStringNotContainsString(UrlVerificacion::CODIGO_EJEMPLO, json_encode($p->fresh()->diseno), 'El código de ejemplo no se guarda en el diseño');
    }

    // ── Generador ─────────────────────────────────────────────────────────────

    public function test_un_diseno_sin_qr_genera_el_mismo_pdf_estructural_que_antes(): void
    {
        $p = $this->plantillaConQr(conQr: false);
        $datos = DatosCredencial::qa();

        $pdf = GeneradorCredencialPdf::generar($p, $datos);
        $observado = false;
        DibujanteQr::$observador = function () use (&$observado) {
            $observado = true;
        };
        $otra = GeneradorCredencialPdf::generar($p, $datos);

        $a = new InspectorPdf($pdf);
        $b = new InspectorPdf($otra);
        $this->assertFalse($observado, 'Sin QR en el diseño no se llama a TCPDF write2DBarcode');
        $this->assertSame($a->textos(), $b->textos());
        $this->assertSame($a->mediaBox(), $b->mediaBox());
        $this->assertSame($a->xobjetos(), $b->xobjetos());
        $this->assertSame($a->fuentes(), $b->fuentes());
        $this->assertSame($a->plantilla(), $b->plantilla());
        // Solo el fondo gris y las dos marcas de la plantilla base viven en su XObject; la página no trae rectángulos.
        $this->assertSame([], $a->rectangulosRellenos());
        $this->assertCount(5, $a->textos());
    }

    public function test_el_qr_no_altera_los_textos_de_la_credencial(): void
    {
        $sin = $this->plantillaConQr(conQr: false);
        $con = $this->plantillaConQr();
        $datos = DatosCredencial::qa();

        $a = new InspectorPdf(GeneradorCredencialPdf::generar($sin, $datos));
        $b = new InspectorPdf(GeneradorCredencialPdf::generar($con, $datos));

        $this->assertSame($a->textos(), $b->textos());
        $this->assertSame($a->mediaBox(), $b->mediaBox());
        $this->assertSame($a->fuentes(), $b->fuentes());
        $this->assertSame($a->plantilla(), $b->plantilla());
    }

    public function test_el_pdf_de_prueba_con_qr_dibuja_un_qr_real_con_el_codigo_de_ejemplo(): void
    {
        $p = $this->plantillaConQr();
        $visto = null;
        DibujanteQr::$observador = function (array $llamada) use (&$visto) {
            $visto = $llamada;
        };

        $r = $this->actingAs($this->admin())->get(route('credential-flow.plantillas.pdf-prueba', $p))->assertOk();

        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertSame(UrlVerificacion::ejemplo(), $visto['url']);
        $this->assertStringContainsString(UrlVerificacion::CODIGO_EJEMPLO, $visto['url']);
        $this->assertNull(Emision::query()->first(), 'El PDF de prueba no crea ninguna emisión');
    }

    public function test_write2dbarcode_recibe_payload_ecc_caja_quiet_zone_y_fondo(): void
    {
        $p = $this->plantillaConQr(['x' => 600.5, 'y' => 440.25, 'width' => 96, 'height' => 96]);
        $llamadas = [];
        DibujanteQr::$observador = function (array $llamada) use (&$llamadas) {
            $llamadas[] = $llamada;
        };

        $pdf = GeneradorCredencialPdf::generarDesde($p->diseno, \Storage::disk('local')->get($p->rutaPdfEsperada()), DatosCredencial::qa(), 2, [], 'https://verificar.ejemplo.test/verificar/ABCDEFGHJKMNPQRSTVWX');

        $this->assertCount(1, $llamadas);
        $l = $llamadas[0];
        $this->assertSame('https://verificar.ejemplo.test/verificar/ABCDEFGHJKMNPQRSTVWX', $l['url'], 'Payload EXACTO: solo la URL pública');
        $this->assertSame('QRCODE,M', $l['tipo'], 'ECC M');
        $this->assertSame([600.5, 440.25, 96.0, 96.0], [$l['x'], $l['y'], $l['w'], $l['h']]);
        $this->assertSame(4, $l['estilo']['padding'], 'Quiet zone de 4 módulos');
        $this->assertSame([255, 255, 255], $l['estilo']['bgcolor'], 'Fondo blanco');
        $this->assertSame([0, 0, 0], $l['estilo']['fgcolor'], 'QR negro');

        // La caja REAL del PDF (rectángulo blanco de fondo) coincide con x, y, ancho y alto del diseño.
        $rects = (new InspectorPdf($pdf))->rectangulosRellenos();
        $fondo = $rects[0];
        $this->assertSame('1 1 1', $fondo['color']);
        foreach ([['x', 600.5], ['y', 440.25], ['w', 96.0], ['h', 96.0]] as [$k, $esperado]) {
            $this->assertEqualsWithDelta($esperado, $fondo[$k], 0.01, "caja: $k");
        }
        // Los módulos negros quedan dentro de la caja, con 4 módulos de margen, y son cuadrados.
        $modulos = array_values(array_filter(array_slice($rects, 1), fn ($r) => $r['color'] === '0 0 0'));
        $this->assertGreaterThan(200, count($modulos));
        $lado = 96 / (33 + 8); // 33 módulos + 4 de quiet zone por lado (URL de 57 caracteres, ECC M)
        foreach ($modulos as $m) {
            $this->assertEqualsWithDelta($lado, $m['w'], 0.001);
            $this->assertEqualsWithDelta($lado, $m['h'], 0.001);
            $this->assertGreaterThanOrEqual(600.5 + 4 * $lado - 0.01, $m['x']);
            $this->assertLessThanOrEqual(600.5 + 96 - 4 * $lado + 0.01, $m['x'] + $m['w']);
            $this->assertGreaterThanOrEqual(440.25 + 4 * $lado - 0.01, $m['y']);
            $this->assertLessThanOrEqual(440.25 + 96 - 4 * $lado + 0.01, $m['y'] + $m['h']);
        }
    }

    public function test_el_qr_es_vectorial_sin_imagenes_nuevas(): void
    {
        $con = $this->plantillaConQr();
        $sin = $this->plantillaConQr(conQr: false);

        $a = new InspectorPdf(GeneradorCredencialPdf::generar($con, DatosCredencial::qa()));
        $b = new InspectorPdf(GeneradorCredencialPdf::generar($sin, DatosCredencial::qa()));

        $this->assertSame($b->xobjetos(), $a->xobjetos(), 'El QR no añade XObjects (ni imágenes) al PDF');
    }

    public function test_el_generador_falla_si_hay_qr_y_no_hay_url(): void
    {
        $p = $this->plantillaConQr();

        try {
            GeneradorCredencialPdf::generarDesde($p->diseno, \Storage::disk('local')->get($p->rutaPdfEsperada()), DatosCredencial::qa(), 2);
            $this->fail('Debía fallar');
        } catch (E $e) {
            $this->assertSame(E::QR_SIN_URL, $e->codigo);
        }
    }

    public function test_el_planificador_qr_valida_schema_tamano_y_limites(): void
    {
        $base = ['page' => ['width' => 792, 'height' => 612], 'elements' => [$this->qr()]];

        $this->assertSame(96.0, PlanificadorQr::planificar($base, self::PAGINA, 2)['size']);
        $this->assertNull(PlanificadorQr::planificar(['page' => $base['page'], 'elements' => []], self::PAGINA, 1));

        $casos = [
            E::SCHEMA_NO_SOPORTADO => [$base, 1],
            E::QR_NO_VALIDO => [['page' => $base['page'], 'elements' => [$this->qr(), $this->qr()]], 2],
            E::FUERA_DE_PAGINA => [['page' => $base['page'], 'elements' => [$this->qr(['x' => 790])]], 2],
        ];
        foreach ($casos as $codigo => [$diseno, $schema]) {
            try {
                PlanificadorQr::planificar($diseno, self::PAGINA, $schema);
                $this->fail("Debía fallar con $codigo");
            } catch (E $e) {
                $this->assertSame($codigo, $e->codigo);
            }
        }
        foreach ([['width' => 100, 'height' => 90], ['width' => 60, 'height' => 60], ['width' => 250, 'height' => 250, 'x' => 10, 'y' => 10]] as $mal) {
            $this->expectQrNoValido(['page' => $base['page'], 'elements' => [$this->qr($mal)]]);
        }
    }

    private function expectQrNoValido(array $diseno): void
    {
        try {
            PlanificadorQr::planificar($diseno, self::PAGINA, 2);
            $this->fail('Debía fallar');
        } catch (E $e) {
            $this->assertSame(E::QR_NO_VALIDO, $e->codigo);
        }
    }

    public function test_un_diseno_schema_1_con_qr_es_rechazado_por_el_planificador_de_texto(): void
    {
        $diseno = ['page' => ['width' => 792, 'height' => 612], 'elements' => [$this->qr()]];

        try {
            PlanificadorTexto::planificar($diseno, DatosCredencial::qa(), self::PAGINA, 1);
            $this->fail('Debía fallar');
        } catch (E $e) {
            $this->assertSame(E::SCHEMA_NO_SOPORTADO, $e->codigo);
        }
        // Y con schema 2 el planificador de texto ignora el QR (lo planifica PlanificadorQr).
        $this->assertSame([], PlanificadorTexto::planificar($diseno, DatosCredencial::qa(), self::PAGINA, 2));
    }

    // ── Emisiones ─────────────────────────────────────────────────────────────

    public function test_la_emision_con_qr_codifica_la_url_del_codigo_y_congela_el_qr_en_el_snapshot(): void
    {
        config(['credential_flow.verificacion.base_url' => 'https://verificar.ejemplo.test']);
        $p = $this->plantillaConQr();
        $vistas = [];
        DibujanteQr::$observador = function (array $l) use (&$vistas) {
            $vistas[] = $l['url'];
        };

        $e = $this->emitirConQr($p);

        $this->assertSame(['https://verificar.ejemplo.test/verificar/'.$e->codigo], $vistas, 'La URL sale del código de ESA emisión');
        $this->assertSame(2, $e->schema_version);
        $this->assertSame(2, $e->generador_snapshot['generador_version']);
        $this->assertSame(['libreria' => 'tcpdf', 'ecc' => 'M', 'quiet_modulos' => 4, 'url_base' => 'https://verificar.ejemplo.test'], $e->generador_snapshot['qr']);
        $qr = collect($e->diseno_snapshot['elements'])->firstWhere('type', 'qr');
        $this->assertEquals([640.0, 460.0, 96.0, 96.0], [$qr['x'], $qr['y'], $qr['width'], $qr['height']]);
        $this->assertNotContains($e->datos_snapshot['nombre_completo'], $vistas);
        foreach ($vistas as $url) {
            $this->assertStringNotContainsString('PERSONA', $url);
            $this->assertStringNotContainsString('20.000', $url);
            $this->assertMatchesRegularExpression('#^https://verificar\.ejemplo\.test/verificar/[0-9A-Z]{20}$#', $url, 'Solo la URL: ni nombre, ni documento, ni JSON, ni ids');
        }
    }

    public function test_emisiones_distintas_llevan_qr_con_urls_distintas(): void
    {
        $p = $this->plantillaConQr();
        $lote = $this->loteCon(3, $p);
        $vistas = [];
        DibujanteQr::$observador = function (array $l) use (&$vistas) {
            $vistas[] = $l['url'];
        };

        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();

        $this->assertCount(3, $vistas);
        $this->assertCount(3, array_unique($vistas));
        $codigos = Emision::pluck('codigo')->all();
        sort($codigos);
        $desdeUrls = array_map(fn ($u) => substr($u, -20), $vistas);
        sort($desdeUrls);
        $this->assertSame($codigos, $desdeUrls);
        $this->assertSame(3, Emision::whereNotNull('generador_snapshot->qr')->count());
    }

    public function test_una_emision_sin_qr_queda_como_hoy_y_sin_bloque_qr(): void
    {
        $sin = $this->plantillaConQr(conQr: false);
        $llamado = false;
        DibujanteQr::$observador = function () use (&$llamado) {
            $llamado = true;
        };

        $e = $this->emitirConQr($sin);

        $this->assertFalse($llamado);
        $this->assertSame(1, $e->schema_version);
        $this->assertSame(2, $e->generador_snapshot['generador_version']);
        $this->assertArrayNotHasKey('qr', $e->generador_snapshot);
        $this->assertNull(collect($e->diseno_snapshot['elements'])->firstWhere('type', 'qr'));
        $this->assertSame([], (new InspectorPdf(\Storage::disk('local')->get($e->pdf_archivo)))->rectangulosRellenos());
    }

    public function test_reemision_de_sin_qr_a_con_qr_conserva_la_v1_historica(): void
    {
        $p = $this->plantillaConQr(conQr: false);
        $v1 = $this->emitirConQr($p);
        $pdfV1 = \Storage::disk('local')->get($v1->pdf_archivo);
        $hashV1 = $v1->pdf_hash;

        // El administrador agrega el QR a la plantilla y reemite.
        $this->guardar($p, array_merge($this->textos(), [$this->qr()]))->assertRedirect();
        $v2 = Emision::findOrFail($this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $v1), ['motivo' => 'Ahora con QR'])->assertCreated()->json('emision.id'));

        $v1->refresh();
        $this->assertSame('revocada', $v1->estado);
        $this->assertSame($hashV1, $v1->pdf_hash, 'La v1 no cambia');
        $this->assertSame($pdfV1, \Storage::disk('local')->get($v1->pdf_archivo), 'El PDF de la v1 sigue siendo el mismo, sin QR');
        $this->assertArrayNotHasKey('qr', $v1->generador_snapshot);
        $this->assertSame(1, $v1->schema_version);
        $this->assertSame(2, $v2->schema_version);
        $this->assertArrayHasKey('qr', $v2->generador_snapshot);
        // La v1 (sin QR) sigue siendo verificable por código, revocada.
        $this->app['auth']->forgetGuards();
        $this->get('/verificar/'.$v1->codigo)->assertOk()->assertSee('Credencial revocada');
        $this->get('/verificar/'.$v2->codigo)->assertOk()->assertSee('Credencial válida');
    }

    public function test_el_qr_de_una_emision_revocada_sigue_funcionando(): void
    {
        $e = $this->emitirConQr($this->plantillaConQr());
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e), ['motivo' => 'Revocada de prueba'])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->get('/verificar/'.$e->codigo)->assertOk()->assertSee('Credencial revocada');
    }

    public function test_las_emisiones_historicas_no_cambian_al_editar_la_plantilla(): void
    {
        $p = $this->plantillaConQr(conQr: false);
        $e = $this->emitirConQr($p);
        $antes = DB::table('cf_emisiones')->where('id', $e->id)->first();
        $pdf = \Storage::disk('local')->get($e->pdf_archivo);

        $this->guardar($p, array_merge($this->textos(), [$this->qr()]))->assertRedirect();

        $this->assertEquals($antes, DB::table('cf_emisiones')->where('id', $e->id)->first());
        $this->assertSame($pdf, \Storage::disk('local')->get($e->pdf_archivo));
    }

    public function test_una_emision_masiva_con_qr_invalido_no_emite_ninguna(): void
    {
        $p = $this->plantillaConQr();
        DB::table('cf_plantillas')->where('id', $p->id)->update(['schema_version' => 1]); // inconsistencia: QR con schema 1
        $lote = $this->loteCon(2, $p->fresh());

        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(422);

        $this->assertSame(0, Emision::count());
        $this->assertSame([], $this->archivosDeEmisiones());
    }

    public function test_el_snapshot_solo_incluye_el_bloque_qr_cuando_hay_qr(): void
    {
        $con = $this->plantillaConQr();
        $sin = $this->plantillaConQr(conQr: false);

        $this->assertArrayHasKey('qr', SnapshotCredencial::generador($con->diseno));
        $this->assertArrayNotHasKey('qr', SnapshotCredencial::generador($sin->diseno));
        $this->assertSame(2, GeneradorCredencialPdf::GENERADOR_VERSION);
    }

    public function test_pdf_base_de_otro_tamano_con_qr_usa_el_tamano_real_del_pdf(): void
    {
        $bytes = PdfBase::crear(612, 792);
        $diseno = ['page' => ['width' => 612, 'height' => 792], 'elements' => [$this->qr(['x' => 500, 'y' => 680])]];

        $pdf = GeneradorCredencialPdf::generarDesde($diseno, $bytes, DatosCredencial::qa(), 2, [], UrlVerificacion::ejemplo());

        $fondo = (new InspectorPdf($pdf))->rectangulosRellenos()[0];
        $this->assertEqualsWithDelta(680.0, $fondo['y'], 0.01);
        $this->assertEqualsWithDelta(500.0, $fondo['x'], 0.01);
    }

    public function test_tcpdf_y_el_editor_usan_el_mismo_numero_de_modulos_para_la_url_de_ejemplo(): void
    {
        // El mismo caso que tests/js/credential-flow.test.mjs (33×33 con ECC M): la matriz puede usar otra máscara,
        // pero el tamaño coincide, así que la caja y la densidad del QR del editor son las del PDF.
        $matriz = (new \TCPDF2DBarcode('https://fycconsultores.com/verificar/QA234567ABCDEFGHJKMN', 'QRCODE,M'))->getBarcodeArray();

        $this->assertSame([33, 33], [$matriz['num_cols'], $matriz['num_rows']]);
    }

    // ── Auditoría ─────────────────────────────────────────────────────────────

    public function test_la_auditoria_acepta_emisiones_con_y_sin_qr_y_las_historicas_de_schema_1(): void
    {
        $this->emitirConQr($this->plantillaConQr());
        $this->emitirConQr($this->plantillaConQr(conQr: false));
        // Una emisión histórica como las de la Fase 7: schema 1 y generador 1, sin bloque qr.
        $historica = Emision::latest('id')->first();
        DB::table('cf_emisiones')->where('id', $historica->id)->update(['generador_snapshot' => json_encode(array_merge($historica->generador_snapshot, ['generador_version' => 1]))]);

        $this->artisan('credential-flow:verificar-emisiones')->expectsOutputToContain('Emisiones revisadas: 2')->assertSuccessful();
    }

    public function test_la_auditoria_detecta_incoherencias_del_qr_sin_reparar_nada(): void
    {
        $e = $this->emitirConQr($this->plantillaConQr());
        $g = $e->generador_snapshot;
        $d = $e->diseno_snapshot;
        $indice = collect($d['elements'])->search(fn ($el) => $el['type'] === 'qr');
        $casos = [
            'schema_version es menor' => fn () => DB::table('cf_emisiones')->where('id', $e->id)->update(['schema_version' => 1]),
            'generador_version es menor que 2' => fn () => DB::table('cf_emisiones')->where('id', $e->id)->update(['generador_snapshot' => json_encode(array_merge($g, ['generador_version' => 1]))]),
            'generador_snapshot.qr falta' => fn () => DB::table('cf_emisiones')->where('id', $e->id)->update(['generador_snapshot' => json_encode(array_diff_key($g, ['qr' => 1]))]),
            'el QR no es cuadrado' => function () use ($e, $d, $indice) {
                $d['elements'][$indice]['height'] = 90;
                DB::table('cf_emisiones')->where('id', $e->id)->update(['diseno_snapshot' => json_encode($d)]);
            },
            'fuera del rango permitido' => function () use ($e, $d, $indice) {
                $d['elements'][$indice]['width'] = $d['elements'][$indice]['height'] = 50;
                DB::table('cf_emisiones')->where('id', $e->id)->update(['diseno_snapshot' => json_encode($d)]);
            },
            'queda fuera de la página' => function () use ($e, $d, $indice) {
                $d['elements'][$indice]['x'] = 780;
                DB::table('cf_emisiones')->where('id', $e->id)->update(['diseno_snapshot' => json_encode($d)]);
            },
            'más de un QR' => function () use ($e, $d, $indice) {
                $d['elements'][] = array_merge($d['elements'][$indice], ['id' => '00000000-0000-4000-8000-000000000999']);
                DB::table('cf_emisiones')->where('id', $e->id)->update(['diseno_snapshot' => json_encode($d)]);
            },
            'bloque qr pero el diseño no tiene QR' => function () use ($e, $d, $indice) {
                unset($d['elements'][$indice]);
                DB::table('cf_emisiones')->where('id', $e->id)->update(['diseno_snapshot' => json_encode(['page' => $d['page'], 'elements' => array_values($d['elements'])])]);
            },
        ];
        foreach ($casos as $texto => $romper) {
            DB::table('cf_emisiones')->where('id', $e->id)->update(['schema_version' => 2, 'generador_snapshot' => json_encode($g), 'diseno_snapshot' => json_encode($d)]);
            $romper();
            $antes = DB::table('cf_emisiones')->where('id', $e->id)->first();

            $this->artisan('credential-flow:verificar-emisiones')->expectsOutputToContain($texto)->assertFailed();

            $this->assertEquals($antes, DB::table('cf_emisiones')->where('id', $e->id)->first(), 'La auditoría no repara: '.$texto);
        }
    }
}
