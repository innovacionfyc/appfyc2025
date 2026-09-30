<?php

namespace Tests\Feature\CredentialFlow;

use App\Support\CredentialFlow\CamposDinamicos;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Generacion\Autoajuste;
use App\Support\CredentialFlow\Generacion\DatosCredencial;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException as E;
use App\Support\CredentialFlow\Generacion\PlanificadorTexto;
use Tests\TestCase;

/** F&C Credential Flow · Fase 5: datos, autoajuste y plan de dibujo (sin PDF ni TCPDF). */
class GeneracionPlanTest extends TestCase
{
    private const PAGINA = ['width' => 792.0, 'height' => 612.0];

    private function el(array $c = []): array
    {
        return array_merge([
            'id' => '00000000-0000-4000-8000-000000000001', 'type' => 'text', 'field' => null, 'text' => 'Texto',
            'x' => 96, 'y' => 200, 'width' => 600, 'height' => 40, 'fontFamily' => 'outfit', 'fontSize' => 22,
            'fontWeight' => 700, 'color' => '#000000', 'align' => 'center',
        ], $c);
    }

    private function diseno(array $elementos): array
    {
        return ['page' => ['width' => 792, 'height' => 612], 'elements' => $elementos];
    }

    private function planificar(array $elementos, ?DatosCredencial $datos = null): array
    {
        return PlanificadorTexto::planificar($this->diseno($elementos), $datos ?? DatosCredencial::qa(), self::PAGINA);
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

    private function vectores(): array
    {
        return json_decode(file_get_contents(__DIR__.'/vectores/vectores-tipograficos.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    // ── Datos ─────────────────────────────────────────────────────────────────

    public function test_dataset_qa_exacto_y_con_las_claves_del_catalogo(): void
    {
        $d = DatosCredencial::qa();

        $this->assertSame('JUAN CARLOS PÉREZ GÓMEZ', $d->valor('nombre_completo'));
        $this->assertSame('C.C. 1.023.456.789', $d->valor('documento'));
        $this->assertSame('GESTIÓN INTEGRAL DE PROPIEDAD HORIZONTAL', $d->valor('evento'));
        $this->assertSame('29 DE SEPTIEMBRE DE 2026', $d->valor('fecha'));
        $this->assertSame('16 HORAS', $d->valor('intensidad_horaria'));
        $this->assertSame(CamposDinamicos::claves(), $d->claves());
    }

    public function test_los_datos_de_generacion_no_leen_el_preview_del_catalogo(): void
    {
        $d = DatosCredencial::fromArray(['nombre_completo' => 'OTRA PERSONA']);
        $planes = $this->planificar([$this->el(['field' => 'nombre_completo'])], $d);

        $this->assertSame('OTRA PERSONA', $planes[0]->lineas[0]['texto']);
        // Otro campo del catálogo, sin dato: falla en lugar de usar el preview.
        $this->assertCodigo(E::CAMPO_SIN_VALOR, fn () => $this->planificar([$this->el(['field' => 'documento'])], $d));
    }

    public function test_texto_fijo_usa_text_y_campo_dinamico_usa_los_datos(): void
    {
        $planes = $this->planificar([
            $this->el(['text' => 'Texto fijo']),
            $this->el(['id' => '00000000-0000-4000-8000-000000000002', 'field' => 'fecha', 'text' => '']),
        ]);

        $this->assertSame('Texto fijo', $planes[0]->lineas[0]['texto']);
        $this->assertNull($planes[0]->campo);
        $this->assertSame('29 DE SEPTIEMBRE DE 2026', $planes[1]->lineas[0]['texto']);
        $this->assertSame('fecha', $planes[1]->campo);
    }

    public function test_campo_desconocido_y_datos_con_claves_invalidas(): void
    {
        $this->assertCodigo(E::CAMPO_DESCONOCIDO, fn () => $this->planificar([$this->el(['field' => 'inventado'])]));
        $this->assertCodigo(E::CAMPO_DESCONOCIDO, fn () => DatosCredencial::fromArray(['inventado' => 'x']));
        $this->assertCodigo(E::CAMPO_SIN_VALOR, fn () => DatosCredencial::fromArray(['fecha' => ''])->valor('fecha'));
    }

    // ── Autoajuste (vectores compartidos con Node) ────────────────────────────

    public function test_autoajuste_coincide_con_los_vectores_compartidos(): void
    {
        $v = $this->vectores();
        $this->assertNotEmpty($v['ajustes']);

        foreach ($v['ajustes'] as $i => $caso) {
            $r = Autoajuste::resolver($caso['ancho'], $caso['anchoCaja'], $caso['fontSize']);
            $e = $caso['esperado'];
            $this->assertEquals($e['size'], $r['size'], "ajuste #$i size");
            $this->assertSame($e['reducido'], $r['reducido'], "ajuste #$i reducido");
            $this->assertSame($e['noCabe'], $r['noCabe'], "ajuste #$i noCabe");
            $this->assertEqualsWithDelta($e['ancho'], $r['ancho'], $v['tolerancia'] + 1e-9, "ajuste #$i ancho");
        }
    }

    public function test_autoajuste_reglas_basicas(): void
    {
        $this->assertSame(['size' => 22.0, 'reducido' => false, 'noCabe' => false, 'ancho' => 100.0], Autoajuste::resolver(100, 120, 22));
        // 22 × 260 / 300 = 19.0667 → 19.00 (pasos de 0,25 hacia abajo)
        $this->assertEquals(19.0, Autoajuste::resolver(300, 260, 22)['size']);
        // Piso: ceil(22 × 0,70 / 0,25) × 0,25 = 15,5 y aun así no cabe → NO_CABE, nunca se trunca.
        $piso = Autoajuste::resolver(400, 200, 22);
        $this->assertEquals(15.5, $piso['size']);
        $this->assertTrue($piso['noCabe']);
        // Tolerancia de 0,01 pt
        $this->assertFalse(Autoajuste::resolver(200.01, 200, 22)['reducido']);
        $this->assertTrue(Autoajuste::resolver(200.0101, 200, 22)['reducido']);
    }

    // ── Plan completo (vectores compartidos con Node) ─────────────────────────

    public function test_planes_coinciden_con_los_vectores_compartidos(): void
    {
        $v = $this->vectores();
        $this->assertNotEmpty($v['planes']);

        foreach ($v['planes'] as $i => $caso) {
            $el = $caso['elemento'];
            $e = $caso['esperado'];
            $datos = $el['field'] !== null ? DatosCredencial::fromArray([$el['field'] => $caso['contenido']]) : DatosCredencial::qa();
            $el['text'] = $el['field'] === null ? $caso['contenido'] : '';

            if ($e['noCabe']) {
                $this->assertCodigo(E::NO_CABE, fn () => $this->planificar([$el], $datos));

                continue;
            }

            $p = $this->planificar([$el], $datos)[0];
            $this->assertEquals($e['size'], $p->fontSizeEfectivo, "plan #$i size");
            $this->assertSame($e['reducido'], $p->reducido, "plan #$i reducido");
            $this->assertCount(count($e['lineas']), $p->lineas, "plan #$i líneas");
            foreach ($e['lineas'] as $j => $l) {
                $this->assertSame($l['texto'], $p->lineas[$j]['texto']);
                $this->assertEqualsWithDelta($l['ancho'], $p->lineas[$j]['ancho'], $v['tolerancia'] + 1e-9, "plan #$i ancho $j");
                $this->assertEqualsWithDelta($l['xInicio'], $p->lineas[$j]['xInicio'], $v['tolerancia'] + 1e-9, "plan #$i x $j");
                $this->assertEqualsWithDelta($l['baseline'], $p->lineas[$j]['baseline'], $v['tolerancia'] + 1e-9, "plan #$i baseline $j");
            }
        }
    }

    public function test_alineaciones_left_center_right_y_una_linea(): void
    {
        $r = [];
        foreach (['left', 'center', 'right'] as $a) {
            $p = $this->planificar([$this->el(['text' => 'Alineado', 'align' => $a])])[0];
            $this->assertCount(1, $p->lineas);
            $r[$a] = $p->lineas[0];
        }

        $ancho = $r['left']['ancho'];
        $this->assertEqualsWithDelta(96, $r['left']['xInicio'], 1e-9);
        $this->assertEqualsWithDelta(96 + (600 - $ancho) / 2, $r['center']['xInicio'], 1e-9);
        $this->assertEqualsWithDelta(96 + 600 - $ancho, $r['right']['xInicio'], 1e-9);
    }

    public function test_linea_base_de_una_linea_usa_la_formula_validada(): void
    {
        // Outfit: ascent 1000, descent -260, unitsPerEm 1000 → y + h/2 + 0,37 × size
        $p = $this->planificar([$this->el(['y' => 100, 'height' => 50, 'fontSize' => 40])])[0];

        $this->assertEqualsWithDelta(100 + 25 + 0.37 * 40, $p->lineas[0]['baseline'], 1e-9);
    }

    public function test_texto_fijo_multilinea_interlineado_y_bloque_centrado(): void
    {
        $unica = $this->planificar([$this->el(['text' => 'b', 'height' => 90])])[0]->lineas[0]['baseline'];
        $p = $this->planificar([$this->el(['text' => "a\nb\r\nc", 'height' => 90])])[0];

        $this->assertCount(3, $p->lineas);
        $paso = DisenoSchema::INTERLINEADO_TEXTO_FIJO * 22;
        $this->assertEqualsWithDelta($paso, $p->lineas[1]['baseline'] - $p->lineas[0]['baseline'], 1e-9);
        $this->assertEqualsWithDelta($paso, $p->lineas[2]['baseline'] - $p->lineas[1]['baseline'], 1e-9);
        $this->assertEqualsWithDelta($unica, $p->lineas[1]['baseline'], 1e-9); // el bloque queda centrado
        $this->assertSame(1.2, DisenoSchema::INTERLINEADO_TEXTO_FIJO);
    }

    public function test_un_campo_dinamico_es_siempre_una_linea_y_los_datos_no_admiten_saltos(): void
    {
        $p = $this->planificar([$this->el(['field' => 'evento', 'fontSize' => 16])])[0];
        $this->assertCount(1, $p->lineas);

        // Un salto de línea en un dato es un carácter de control: DatosCredencial lo rechaza antes de planificar.
        $this->assertCodigo(E::DATO_INVALIDO, fn () => DatosCredencial::fromArray(['evento' => "LINEA UNO\nLINEA DOS"]));
    }

    public function test_datos_credencial_ultima_barrera(): void
    {
        $this->assertCodigo(E::DATO_INVALIDO, fn () => DatosCredencial::fromArray(['nombre_completo' => str_repeat('A', 101)]));
        $this->assertCodigo(E::DATO_INVALIDO, fn () => DatosCredencial::fromArray(['documento' => "12\x0034"]));
        $this->assertCodigo(E::DATO_INVALIDO, fn () => DatosCredencial::fromArray(['documento' => "\xC3\x28"]));
        $this->assertCodigo(E::CAMPO_DESCONOCIDO, fn () => DatosCredencial::fromArray(['NOMBRE_COMPLETO' => 'X']));
        $this->assertCodigo(E::CAMPO_SIN_VALOR, fn () => DatosCredencial::fromArray(['documento' => 123456]));

        // NFC: e + acento combinado se guarda como é
        $this->assertSame("Caf\u{00E9}", DatosCredencial::fromArray(['evento' => "Cafe\u{0301}"])->valor('evento'));
    }

    public function test_texto_fijo_largo_no_se_reduce_ni_se_trunca(): void
    {
        $largo = str_repeat('MMMM ', 40);
        $p = $this->planificar([$this->el(['text' => $largo, 'width' => 100])])[0];

        $this->assertSame(22.0, $p->fontSizeEfectivo);
        $this->assertSame($largo, $p->lineas[0]['texto']);
        $this->assertGreaterThan(100, $p->lineas[0]['ancho']); // puede exceder su caja (limitación V1)
    }

    public function test_el_texto_se_normaliza_a_nfc(): void
    {
        $p = $this->planificar([$this->el(['text' => "Cafe\u{0301}"])])[0];

        $this->assertSame("Caf\u{00E9}", $p->lineas[0]['texto']);
    }

    // ── Validaciones ──────────────────────────────────────────────────────────

    public function test_fuentes_heredadas_no_generan(): void
    {
        foreach (['Figtree', 'Arial', 'sans-serif'] as $f) {
            $this->assertCodigo(E::FUENTE_NO_REPRODUCIBLE, fn () => $this->planificar([$this->el(['fontFamily' => $f])]));
        }
        $this->assertCodigo(E::FUENTE_NO_REPRODUCIBLE, fn () => $this->planificar([$this->el(['fontWeight' => 350])]));
    }

    public function test_caracteres_no_soportados_fallan_en_texto_fijo_y_dinamico(): void
    {
        $this->assertCodigo(E::CARACTER_NO_SOPORTADO, fn () => $this->planificar([$this->el(['text' => 'Hola 日本'])]));
        $this->assertCodigo(E::CARACTER_NO_SOPORTADO, fn () => $this->planificar([$this->el(['text' => "ok\nlínea 2 😀"])]));
        $this->assertCodigo(E::CARACTER_NO_SOPORTADO, fn () => $this->planificar(
            [$this->el(['field' => 'nombre_completo'])],
            DatosCredencial::fromArray(['nombre_completo' => 'JOSÉ 日'])
        ));
    }

    public function test_no_cabe_es_un_error(): void
    {
        $this->assertCodigo(E::NO_CABE, fn () => $this->planificar([$this->el(['field' => 'evento', 'width' => 150, 'fontWeight' => 400])]));
    }

    public function test_elemento_fuera_de_pagina(): void
    {
        $this->assertCodigo(E::FUERA_DE_PAGINA, fn () => $this->planificar([$this->el(['x' => 400, 'width' => 600])]));
        $this->assertCodigo(E::FUERA_DE_PAGINA, fn () => $this->planificar([$this->el(['y' => 590, 'height' => 40])]));
    }

    public function test_pagina_distinta_schema_y_diseno_ausente(): void
    {
        $this->assertCodigo(E::PAGINA_DISTINTA, fn () => PlanificadorTexto::planificar($this->diseno([]), DatosCredencial::qa(), ['width' => 612.0, 'height' => 792.0]));
        // dentro de la tolerancia de 0,05 pt sí se acepta
        $this->assertSame([], PlanificadorTexto::planificar($this->diseno([]), DatosCredencial::qa(), ['width' => 792.04, 'height' => 611.96]));
        $this->assertCodigo(E::PAGINA_DISTINTA, fn () => PlanificadorTexto::planificar($this->diseno([]), DatosCredencial::qa(), ['width' => 792.06, 'height' => 612.0]));

        $this->assertCodigo(E::SCHEMA_NO_SOPORTADO, fn () => PlanificadorTexto::planificar($this->diseno([]), DatosCredencial::qa(), self::PAGINA, 2));
        $this->assertCodigo(E::SIN_DISENO, fn () => PlanificadorTexto::planificar(['elements' => []], DatosCredencial::qa(), self::PAGINA));
    }
}
