<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\CamposDinamicos;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\FuentesCredential;
use App\Support\CredentialFlow\Generacion\DatosCredencial;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException as E;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use App\Support\CredentialFlow\Generacion\Multilinea;
use App\Support\CredentialFlow\Generacion\PlanificadorTexto;
use App\Support\CredentialFlow\Participantes\CeldaCruda;
use App\Support\CredentialFlow\Participantes\ValidadorDatosComunes;
use App\Support\CredentialFlow\Participantes\ValidadorParticipante;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;
use Tests\Feature\CredentialFlow\Support\PdfBase;

/**
 * Campos dinámicos con prefijo/sufijo y varias líneas (schema 3): plan de dibujo, PDF, guardado del diseño, límites de
 * caracteres y compatibilidad con los diseños existentes. El caso de referencia es un certificado real: nombre,
 * «C.C. 73.156.827», evento largo en varias líneas, fecha e intensidad como una frase.
 */
class TextoDinamicoAvanzadoTest extends EmisionesTestCase
{
    private const PAGINA = ['width' => 792.0, 'height' => 612.0];

    private const EVENTO = 'X CONGRESO NACIONAL DE DIRECCIONAMIENTO ESTRATÉGICO Y PLANEACIÓN CON ÉNFASIS EN MODELO INTEGRADO DE PLANEACIÓN Y GESTIÓN VERSIÓN 7';

    private function datos(array $extra = []): DatosCredencial
    {
        return DatosCredencial::fromArray($extra + [
            'nombre_completo' => 'ALEJANDRO JOSÉ CORONEL GONZÁLEZ',
            'documento' => '73.156.827',
            'evento' => self::EVENTO,
            'fecha' => 'Los días 1 y 2 de octubre de 2026',
            'intensidad_horaria' => '25 horas.',
        ]);
    }

    private function plan(array $elemento, ?DatosCredencial $datos = null)
    {
        return PlanificadorTexto::planificar(['page' => ['width' => 792, 'height' => 612], 'elements' => [$elemento]], $datos ?? $this->datos(), self::PAGINA)[0];
    }

    private function ancho(string $texto, float $size = 22, int $peso = 700): float
    {
        return FuentesCredential::medirTexto($texto, 'outfit', $peso, $size)['ancho'];
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

    // ── Prefijo y sufijo ──────────────────────────────────────────────────────────────────────────────────────

    public function test_prefijo_sin_sufijo_documento_con_cc_es_un_solo_texto_centrado(): void
    {
        $plan = $this->plan($this->elemento(['field' => 'documento', 'prefix' => 'C.C. ']));

        $this->assertCount(1, $plan->lineas); // NO hay una caja aparte para el prefijo
        $l = $plan->lineas[0];
        $this->assertSame('C.C. 73.156.827', $l['texto']);
        $this->assertEqualsWithDelta($this->ancho('C.C. 73.156.827'), $l['ancho'], 0.001);
        $this->assertEqualsWithDelta(96 + (600 - $l['ancho']) / 2, $l['xInicio'], 0.001); // centrado el TEXTO COMPLETO
        $this->assertGreaterThan($this->ancho('73.156.827'), $l['ancho']);
    }

    public function test_sufijo_sin_prefijo(): void
    {
        $plan = $this->plan($this->elemento(['field' => 'intensidad_horaria', 'suffix' => ' en total']), $this->datos(['intensidad_horaria' => '25 horas']));

        $this->assertSame('25 horas en total', $plan->lineas[0]['texto']);
        $this->assertEqualsWithDelta(96 + (600 - $this->ancho('25 horas en total')) / 2, $plan->lineas[0]['xInicio'], 0.001);
    }

    public function test_prefijo_y_sufijo_juntos(): void
    {
        $plan = $this->plan($this->elemento(['field' => 'documento', 'prefix' => 'C.C. ', 'suffix' => ' (cédula)']));

        $this->assertSame('C.C. 73.156.827 (cédula)', $plan->lineas[0]['texto']);
        $this->assertCount(1, $plan->lineas);
    }

    public function test_intensidad_como_frase_completa_en_una_sola_linea_con_alineacion_a_la_izquierda_y_derecha(): void
    {
        $e = $this->elemento(['field' => 'intensidad_horaria', 'prefix' => 'con una intensidad académica de: ']);
        $plan = $this->plan($e);

        $this->assertSame('con una intensidad académica de: 25 horas.', $plan->lineas[0]['texto']);
        $this->assertFalse($plan->reducido);

        $this->assertEqualsWithDelta(96, $this->plan(array_merge($e, ['align' => 'left']))->lineas[0]['xInicio'], 0.001);
        $derecha = $this->plan(array_merge($e, ['align' => 'right']))->lineas[0];
        $this->assertEqualsWithDelta(96 + 600 - $derecha['ancho'], $derecha['xInicio'], 0.001);
    }

    public function test_el_prefijo_cuenta_en_el_autoajuste_de_una_sola_linea(): void
    {
        // Con la caja justa para el número, el prefijo hace que se reduzca: se mide TODO el texto.
        $caja = round($this->ancho('73.156.827') + 4, 2);
        $solo = $this->plan($this->elemento(['field' => 'documento', 'width' => $caja]));
        $con = $this->plan($this->elemento(['field' => 'documento', 'width' => $caja, 'prefix' => 'C.C. ']));

        $this->assertFalse($solo->reducido);
        $this->assertTrue($con->reducido);
        $this->assertLessThan($solo->fontSizeEfectivo, $con->fontSizeEfectivo);
    }

    public function test_diseno_antiguo_sin_prefijo_ni_sufijo_se_dibuja_exactamente_igual(): void
    {
        $viejo = $this->elemento(['field' => 'documento']);
        $vacios = $viejo + ['prefix' => '', 'suffix' => '', 'multiline' => false];

        $a = $this->plan($viejo);
        $b = $this->plan($vacios);

        $this->assertEquals($a, $b);
        $this->assertSame('73.156.827', $a->lineas[0]['texto']);
        $this->assertEqualsWithDelta(96 + (600 - $this->ancho('73.156.827')) / 2, $a->lineas[0]['xInicio'], 0.001);
    }

    public function test_un_texto_fijo_ignora_prefijo_sufijo_y_varias_lineas(): void
    {
        $plan = $this->plan($this->elemento(['text' => 'Hola', 'prefix' => 'X ', 'suffix' => ' Y', 'multiline' => true]));

        $this->assertSame(['Hola'], array_column($plan->lineas, 'texto'));
    }

    // ── Varias líneas ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_evento_largo_multilinea_se_reparte_por_palabras_sin_reducir_el_tamano(): void
    {
        $plan = $this->plan($this->elemento(['field' => 'evento', 'multiline' => true, 'height' => 110, 'fontSize' => 20]));

        $textos = array_column($plan->lineas, 'texto');
        $this->assertGreaterThan(1, count($textos));
        $this->assertSame(self::EVENTO, implode(' ', $textos), 'no se pierde ni se parte ninguna palabra');
        $this->assertFalse($plan->reducido, 'primero se envuelve; no se encoge un evento largo');
        $this->assertSame(20.0, $plan->fontSizeEfectivo);
        foreach ($plan->lineas as $l) {
            $this->assertLessThanOrEqual(600 + 0.01, $l['ancho']);
            $this->assertEqualsWithDelta($this->ancho($l['texto'], 20), $l['ancho'], 0.001);
        }
    }

    public function test_multilinea_centra_cada_linea_y_el_bloque_en_la_caja(): void
    {
        $plan = $this->plan($this->elemento(['field' => 'evento', 'multiline' => true, 'height' => 110, 'fontSize' => 20, 'y' => 250]));

        foreach ($plan->lineas as $l) {
            $this->assertEqualsWithDelta(96 + (600 - $l['ancho']) / 2, $l['xInicio'], 0.001, 'cada línea centrada por separado');
        }
        $n = count($plan->lineas);
        $paso = DisenoSchema::INTERLINEADO_TEXTO_FIJO * 20;
        for ($i = 1; $i < $n; $i++) {
            $this->assertEqualsWithDelta($paso, $plan->lineas[$i]['baseline'] - $plan->lineas[$i - 1]['baseline'], 0.001, 'interlineado coherente');
        }
        // El bloque queda centrado verticalmente: la media de las líneas base es la línea base de una sola línea en esa caja.
        $una = $this->plan($this->elemento(['field' => 'nombre_completo', 'height' => 110, 'fontSize' => 20, 'y' => 250]))->lineas[0]['baseline'];
        $media = array_sum(array_column($plan->lineas, 'baseline')) / $n;
        $this->assertEqualsWithDelta($una, $media, 0.001);
    }

    public function test_multilinea_alineada_a_izquierda_y_derecha(): void
    {
        $izq = $this->plan($this->elemento(['field' => 'evento', 'multiline' => true, 'height' => 110, 'fontSize' => 20, 'align' => 'left']));
        foreach ($izq->lineas as $l) {
            $this->assertEqualsWithDelta(96, $l['xInicio'], 0.001);
        }
        $der = $this->plan($this->elemento(['field' => 'evento', 'multiline' => true, 'height' => 110, 'fontSize' => 20, 'align' => 'right']));
        foreach ($der->lineas as $l) {
            $this->assertEqualsWithDelta(96 + 600 - $l['ancho'], $l['xInicio'], 0.001);
        }
    }

    public function test_texto_corto_con_multilinea_no_salta_de_linea(): void
    {
        $plan = $this->plan($this->elemento(['field' => 'nombre_completo', 'multiline' => true, 'height' => 80]));

        $this->assertSame(['ALEJANDRO JOSÉ CORONEL GONZÁLEZ'], array_column($plan->lineas, 'texto'));
        $this->assertFalse($plan->reducido);
    }

    public function test_multilinea_con_prefijo_trata_prefijo_valor_y_sufijo_como_un_solo_texto(): void
    {
        $plan = $this->plan($this->elemento(['field' => 'evento', 'multiline' => true, 'height' => 110, 'fontSize' => 20, 'prefix' => 'Evento: ', 'suffix' => ' (virtual)']));

        $this->assertSame('Evento: '.self::EVENTO.' (virtual)', implode(' ', array_column($plan->lineas, 'texto')));
    }

    public function test_si_no_cabe_en_el_alto_se_reduce_hasta_el_minimo_y_no_mas(): void
    {
        // Alto un poco menor que el que pide el bloque a 20 pt: se baja de tamaño, nunca por debajo del 70 %.
        $natural = count($this->plan($this->elemento(['field' => 'evento', 'multiline' => true, 'height' => 200, 'fontSize' => 20]))->lineas);
        $alto = round($natural * DisenoSchema::INTERLINEADO_TEXTO_FIJO * 20 - 6, 2);
        $plan = $this->plan($this->elemento(['field' => 'evento', 'multiline' => true, 'height' => $alto, 'fontSize' => 20]));

        $this->assertTrue($plan->reducido);
        $this->assertGreaterThanOrEqual(20 * DisenoSchema::ESCALA_MINIMA_TEXTO_DINAMICO - 0.25, $plan->fontSizeEfectivo);
        $this->assertLessThan(20, $plan->fontSizeEfectivo);
        $this->assertLessThanOrEqual($alto + 0.01, count($plan->lineas) * DisenoSchema::INTERLINEADO_TEXTO_FIJO * $plan->fontSizeEfectivo);
    }

    public function test_si_ni_al_minimo_cabe_se_niega_a_dibujar_en_vez_de_truncar(): void
    {
        $this->assertCodigo(E::NO_CABE, fn () => $this->plan($this->elemento(['field' => 'evento', 'multiline' => true, 'height' => 40, 'fontSize' => 22])));
    }

    public function test_una_palabra_mas_ancha_que_la_caja_nunca_se_parte(): void
    {
        $this->assertCodigo(E::NO_CABE, fn () => $this->plan($this->elemento(['field' => 'evento', 'multiline' => true, 'width' => 60, 'height' => 600, 'y' => 10, 'fontSize' => 22])));
    }

    public function test_saltos_manuales_se_respetan_y_cada_parrafo_se_envuelve_aparte(): void
    {
        $medir = fn (string $t, float $s) => $this->ancho($t, $s);
        $r = Multilinea::resolver($medir, ['UNO DOS', 'TRES', '', 'CUATRO'], 600, 200, 20);

        $this->assertSame(['UNO DOS', 'TRES', '', 'CUATRO'], $r['lineas']);
        $this->assertFalse($r['noCabe']);

        // Un párrafo largo se envuelve en su propio bloque sin mezclarse con el siguiente.
        $estrecho = Multilinea::envolver(fn (string $t) => $this->ancho($t, 20), ['AAAA BBBB CCCC', 'DDDD'], $this->ancho('AAAA BBBB', 20) + 0.5, 0.01);
        $this->assertSame(['AAAA BBBB', 'CCCC', 'DDDD'], $estrecho);
    }

    // ── PDF ───────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_pdf_del_certificado_de_referencia_es_valido_y_dibuja_cada_linea_donde_el_plan_dice(): void
    {
        $elementos = [
            $this->elemento(['field' => 'nombre_completo', 'y' => 150, 'fontSize' => 30, 'fontWeight' => 800]),
            $this->elemento(['field' => 'documento', 'prefix' => 'C.C. ', 'y' => 190]),
            $this->elemento(['field' => 'evento', 'multiline' => true, 'y' => 250, 'height' => 100, 'fontSize' => 20]),
            $this->elemento(['field' => 'fecha', 'y' => 370]),
            $this->elemento(['field' => 'intensidad_horaria', 'prefix' => 'con una intensidad académica de: ', 'y' => 410]),
        ];
        $p = $this->crearPlantilla();
        Storage::disk('local')->put($p->rutaPdfEsperada(), PdfBase::crear(792, 612));
        $p->update(['diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => $elementos]]);
        $p = $p->fresh();

        $bytes = GeneradorCredencialPdf::generar($p, $this->datos());
        $pdf = new InspectorPdf($bytes);

        $this->assertTrue($pdf->firmaValida());
        $this->assertSame(1, $pdf->paginas());

        $planes = PlanificadorTexto::planificar($p->diseno, $this->datos(), self::PAGINA);
        $esperadas = [];
        foreach ($planes as $plan) {
            foreach ($plan->lineas as $l) {
                $esperadas[] = [$plan, $l];
            }
        }
        $textos = $pdf->textos();
        $this->assertCount(count($esperadas), $textos);
        foreach ($esperadas as $i => [$plan, $l]) {
            $this->assertSame($l['texto'], $textos[$i]['texto']);
            $this->assertEqualsWithDelta($l['xInicio'], $textos[$i]['x'], 0.01);
            $this->assertEqualsWithDelta($l['baseline'], $textos[$i]['baseline'], 0.01);
            $this->assertEqualsWithDelta($plan->fontSizeEfectivo, $textos[$i]['size'], 0.0005);
        }
        $todo = implode("\n", array_column($textos, 'texto'));
        $this->assertStringContainsString('C.C. 73.156.827', $todo);
        $this->assertStringContainsString('con una intensidad académica de: 25 horas.', $todo);
        $this->assertGreaterThan(5, count($textos), 'el evento ocupa varias líneas');
    }

    // ── Versión del schema y compatibilidad ───────────────────────────────────────────────────────────────────

    public function test_schema_version_sube_a_3_solo_si_algun_campo_usa_prefijo_sufijo_o_varias_lineas(): void
    {
        $base = fn (array $els) => ['page' => ['width' => 792, 'height' => 612], 'elements' => $els];
        $qr = ['id' => sprintf('00000000-0000-4000-8000-%012d', 900), 'type' => 'qr', 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 100];

        $this->assertSame(1, DisenoSchema::versionPara($base([$this->elemento(['field' => 'documento'])])));
        $this->assertSame(1, DisenoSchema::versionPara($base([$this->elemento(['field' => 'documento', 'prefix' => '', 'suffix' => '', 'multiline' => false])])));
        $this->assertSame(2, DisenoSchema::versionPara($base([$this->elemento(['field' => 'documento']), $qr])));
        $this->assertSame(3, DisenoSchema::versionPara($base([$this->elemento(['field' => 'documento', 'prefix' => 'C.C. '])])));
        $this->assertSame(3, DisenoSchema::versionPara($base([$this->elemento(['field' => 'evento', 'multiline' => true])])));
        $this->assertSame(3, DisenoSchema::versionPara($base([$this->elemento(['field' => 'evento', 'suffix' => '.']), $qr])), 'con QR y texto avanzado manda el 3');
        // Un texto fijo con esas claves (no debería existir) no cambia la versión.
        $this->assertSame(1, DisenoSchema::versionPara($base([$this->elemento(['text' => 'Hola', 'prefix' => 'x'])])));
        $this->assertTrue(DisenoSchema::soportada(3));
        $this->assertSame(3, DisenoSchema::VERSION_TEXTO_AVANZADO);
        $this->assertSame(3, GeneradorCredencialPdf::GENERADOR_VERSION);
    }

    public function test_el_editor_recibe_el_limite_de_afijos_y_la_version(): void
    {
        $schema = DisenoSchema::paraEditor();

        $this->assertSame(100, $schema['afijoMax']);
        $this->assertSame(3, $schema['versionTextoAvanzado']);
    }

    // ── Guardado del diseño ───────────────────────────────────────────────────────────────────────────────────

    private function guardar(Plantilla $p, array $elementos)
    {
        return $this->actingAs($this->admin())->putJson(route('credential-flow.plantillas.diseno.update', $p), ['diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => $elementos]]);
    }

    public function test_guardar_conserva_el_espacio_del_prefijo_y_sube_el_schema_a_3(): void
    {
        $p = $this->crearPlantilla();
        $this->guardar($p, [
            $this->elemento(['field' => 'documento', 'prefix' => 'C.C. ']),
            $this->elemento(['field' => 'intensidad_horaria', 'prefix' => 'con una intensidad académica de: ']),
            $this->elemento(['field' => 'evento', 'multiline' => true, 'height' => 90, 'suffix' => ' (virtual) ']),
        ])->assertRedirect(route('credential-flow.plantillas.editor', $p));

        $p = $p->fresh();
        $this->assertSame(3, (int) $p->schema_version);
        $e = $p->diseno['elements'];
        $this->assertSame('C.C. ', $e[0]['prefix'], 'el espacio final NO se recorta');
        $this->assertSame('con una intensidad académica de: ', $e[1]['prefix']);
        $this->assertTrue($e[2]['multiline']);
        $this->assertSame(' (virtual) ', $e[2]['suffix'], 'espacios al inicio y al final conservados');
        $this->assertArrayNotHasKey('suffix', $e[0]);
        $this->assertArrayNotHasKey('multiline', $e[0]);
    }

    public function test_guardar_un_diseno_sin_las_opciones_nuevas_queda_identico_al_de_siempre(): void
    {
        $p = $this->crearPlantilla();
        $this->guardar($p, [
            $this->elemento(['field' => 'documento', 'prefix' => '', 'suffix' => null, 'multiline' => false]),
            $this->elemento(['text' => 'Hola']),
        ])->assertRedirect();

        $p = $p->fresh();
        $this->assertSame(1, (int) $p->schema_version);
        foreach ($p->diseno['elements'] as $e) {
            $this->assertSame(['id', 'type', 'field', 'text', 'x', 'y', 'width', 'height', 'fontFamily', 'fontSize', 'fontWeight', 'color', 'align'], array_keys($e));
        }
    }

    public function test_el_prefijo_se_guarda_exactamente_como_se_escribio_y_el_recorte_global_de_la_app_sigue_igual(): void
    {
        $p = $this->crearPlantilla();
        $this->guardar($p, [$this->elemento(['field' => 'documento', 'prefix' => 'C.C. ', 'suffix' => '  fin'])])->assertRedirect();

        $e = $p->fresh()->diseno['elements'][0];
        $this->assertSame('C.C. ', $e['prefix']);
        $this->assertSame(bin2hex('C.C. '), bin2hex($e['prefix']), 'C.C. + un solo espacio, sin recortes ni espacios extra');
        $this->assertSame('  fin', $e['suffix']);

        // La excepción es SOLO de esta petición: el recorte general de la aplicación no cambió.
        Route::middleware('web')->post('/__prueba-trim', fn (Request $r) => response()->json(['prefix' => $r->input('prefix'), 'suffix' => $r->input('suffix'), 'nombre' => $r->input('nombre')]));
        $this->postJson('/__prueba-trim', ['prefix' => 'C.C. ', 'suffix' => ' x ', 'nombre' => '  Ana  '])
            ->assertOk()->assertExactJson(['prefix' => 'C.C.', 'suffix' => 'x', 'nombre' => 'Ana']);
    }

    public function test_un_prefijo_solo_de_espacios_se_descarta_y_el_diseno_sigue_en_schema_1(): void
    {
        $p = $this->crearPlantilla();
        $this->guardar($p, [$this->elemento(['field' => 'documento', 'prefix' => '   ', 'suffix' => ' '])])->assertRedirect();

        $p = $p->fresh();
        $this->assertSame(1, (int) $p->schema_version);
        $this->assertArrayNotHasKey('prefix', $p->diseno['elements'][0]);
        $this->assertArrayNotHasKey('suffix', $p->diseno['elements'][0]);
    }

    public function test_c_c_73_156_827_exacto_sin_doble_cc_ni_espacios_perdidos_o_dobles_y_sin_formato_automatico_de_puntos(): void
    {
        $e = $this->elemento(['field' => 'documento', 'prefix' => 'C.C. ']);

        $con = $this->plan($e)->lineas[0]['texto'];
        $this->assertSame('C.C. 73.156.827', $con);
        $this->assertSame(1, preg_match('/^C\.C\. 73\.156\.827$/', $con));
        $this->assertStringNotContainsString('C.C. C.C.', $con);
        $this->assertStringNotContainsString('  ', $con);
        $this->assertStringNotContainsString('C.C.73', $con);

        // Un documento sin puntos se imprime tal cual (no se le da formato automático).
        $sinPuntos = $this->plan($e, $this->datos(['documento' => '10539754']))->lineas[0]['texto'];
        $this->assertSame('C.C. 10539754', $sinPuntos);
    }

    public function test_no_se_aceptan_opciones_en_texto_fijo_ni_en_qr_ni_valores_invalidos(): void
    {
        $p = $this->crearPlantilla();
        $qr = ['id' => sprintf('00000000-0000-4000-8000-%012d', 901), 'type' => 'qr', 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 100];

        $this->guardar($p, [$this->elemento(['text' => 'Hola', 'prefix' => 'C.C. '])])->assertStatus(422);
        $this->guardar($p, [$this->elemento(['text' => 'Hola', 'multiline' => true])])->assertStatus(422);
        $this->guardar($p, [$qr + ['prefix' => 'x']])->assertStatus(422);
        $this->guardar($p, [$this->elemento(['field' => 'documento', 'prefix' => "C.C.\n"])])->assertStatus(422);
        $this->guardar($p, [$this->elemento(['field' => 'documento', 'prefix' => str_repeat('a', 101)])])->assertStatus(422);
        $this->guardar($p, [$this->elemento(['field' => 'documento', 'suffix' => str_repeat('a', 101)])])->assertStatus(422);
        $this->guardar($p, [$this->elemento(['field' => 'documento', 'multiline' => 'quizá'])])->assertStatus(422);
        $this->guardar($p, [$this->elemento(['field' => 'documento', 'prefix' => str_repeat('a', 100)])])->assertRedirect();
    }

    // ── Límites y documento ───────────────────────────────────────────────────────────────────────────────────

    public function test_limite_de_caracteres_de_evento_e_intensidad_ampliado_a_255(): void
    {
        $this->assertSame(255, CamposDinamicos::todos()['intensidad_horaria']['maxLongitud']);
        $this->assertSame(255, CamposDinamicos::todos()['evento']['maxLongitud']);

        $ok = ValidadorDatosComunes::validar(['evento' => str_repeat('A', 255), 'fecha' => 'Los días 1 y 2 de octubre de 2026', 'intensidad_horaria' => str_repeat('a', 255)]);
        $this->assertSame([], $ok['errores']);

        $largo = ValidadorDatosComunes::validar(['evento' => str_repeat('A', 256), 'fecha' => 'x', 'intensidad_horaria' => str_repeat('a', 256)]);
        $this->assertSame(['evento', 'intensidad_horaria'], array_keys($largo['errores']));
        $this->assertSame('Intensidad horaria no puede superar los 255 caracteres.', $largo['errores']['intensidad_horaria']);

        // El generador acepta ahora un dato largo (antes se negaba a partir de 30).
        $this->assertSame(str_repeat('a', 100), DatosCredencial::fromArray(['intensidad_horaria' => str_repeat('a', 100)])->valor('intensidad_horaria'));
    }

    public function test_el_documento_sigue_siendo_el_dato_puro_y_nunca_lleva_cc_por_el_sistema(): void
    {
        $ok = ValidadorParticipante::validar(CeldaCruda::texto('Alejandro José Coronel González'), CeldaCruda::texto('73.156.827'));

        $this->assertSame('73.156.827', $ok->documento);
        $this->assertSame('1.023.456.789', DatosCredencial::qa()->valor('documento'));
        $this->assertSame('1.023.456.789', CamposDinamicos::todos()['documento']['preview']);
    }

    // ── Emisión real ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_una_emision_con_prefijo_y_varias_lineas_guarda_schema_3_y_un_pdf_valido(): void
    {
        $elementos = [
            $this->elemento(['field' => 'nombre_completo', 'y' => 60]),
            $this->elemento(['field' => 'documento', 'prefix' => 'C.C. ', 'y' => 110]),
            $this->elemento(['field' => 'evento', 'multiline' => true, 'y' => 160, 'height' => 100, 'fontSize' => 20]),
            $this->elemento(['field' => 'fecha', 'y' => 280]),
            $this->elemento(['field' => 'intensidad_horaria', 'prefix' => 'con una intensidad académica de: ', 'y' => 330]),
        ];
        $p = $this->plantillaLista($elementos);
        $p->update(['schema_version' => DisenoSchema::versionPara($p->diseno)]);
        $lote = $this->loteCon(0, $p->fresh());
        $lote->update(['datos_comunes' => ['evento' => self::EVENTO, 'fecha' => 'Los días 1 y 2 de octubre de 2026', 'intensidad_horaria' => '25 horas.']]);
        $participante = $this->participante($lote, 'Alejandro José Coronel González', '73.156.827');

        $respuesta = $this->emitirPor($lote->fresh(), $participante)->assertCreated();

        $e = Emision::findOrFail($respuesta->json('emision.id'));
        $this->assertSame(3, $e->schema_version);
        $this->assertSame(3, $e->generador_snapshot['generador_version']);
        $this->assertSame('C.C. ', collect($e->diseno_snapshot['elements'])->firstWhere('field', 'documento')['prefix']);
        $this->assertSame('73.156.827', $e->datos_snapshot['documento'], 'el dato guardado es el documento puro');

        $pdf = new InspectorPdf(Storage::disk('local')->get($e->pdf_archivo));
        $this->assertTrue($pdf->firmaValida());
        $this->assertStringContainsString('C.C. 73.156.827', implode("\n", array_column($pdf->textos(), 'texto')));
        $this->artisan('credential-flow:verificar-emisiones')->expectsOutputToContain('Todo correcto')->assertExitCode(0);
    }
}
