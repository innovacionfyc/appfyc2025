<?php

namespace Tests\Feature\CredentialFlow;

use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\FuentesCredential;
use App\Support\CredentialFlow\LectorTtf;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** F&C Credential Flow · Fase 4: fuentes reproducibles (Outfit), métricas y paridad editor ↔ PDF. */
class FuentesTest extends CredentialFlowTestCase
{
    private function elemento(array $cambios = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'type' => 'text',
            'field' => null,
            'text' => 'Texto fijo',
            'x' => 96,
            'y' => 223.38,
            'width' => 600,
            'height' => 40,
            'fontFamily' => 'outfit',
            'fontSize' => 22,
            'fontWeight' => 700,
            'color' => '#000000',
            'align' => 'center',
        ], $cambios);
    }

    private function guardar($plantilla, array $elementos)
    {
        return $this->actingAs($this->admin())->putJson(
            route('credential-flow.plantillas.diseno.update', $plantilla),
            ['diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => $elementos]]
        );
    }

    private function vectores(): array
    {
        return json_decode(file_get_contents(__DIR__.'/vectores/vectores-tipograficos.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    // ── Archivos y procedencia ────────────────────────────────────────────────

    public function test_los_seis_ttf_de_outfit_y_su_licencia_estan_en_el_repositorio(): void
    {
        foreach (FuentesCredential::pesosReproducibles() as $peso) {
            $this->assertFileExists(FuentesCredential::rutaArchivo('outfit', $peso));
        }
        $ofl = file_get_contents(FuentesCredential::directorio().'/outfit/OFL.txt');
        $this->assertStringContainsString('SIL OPEN FONT LICENSE Version 1.1', $ofl);
        $this->assertStringContainsString('Outfit Project Authors', $ofl);
    }

    public function test_no_hay_fuentes_neutraface_ni_otros_archivos_de_fuente(): void
    {
        $archivos = collect(glob(FuentesCredential::directorio().'/outfit/*'))->map(fn ($f) => basename($f))->sort()->values()->all();

        $this->assertSame([
            'OFL.txt', 'Outfit-Bold.ttf', 'Outfit-ExtraBold.ttf', 'Outfit-Light.ttf',
            'Outfit-Medium.ttf', 'Outfit-Regular.ttf', 'Outfit-SemiBold.ttf',
        ], $archivos);
        $this->assertSame([], glob(FuentesCredential::directorio().'/*neutra*'));
    }

    public function test_metricas_json_coincide_con_los_ttf_y_con_sus_hashes(): void
    {
        $this->artisan('credential-flow:fuentes-metricas', ['--verify' => true])->assertSuccessful();

        foreach (FuentesCredential::pesosReproducibles() as $peso) {
            $m = FuentesCredential::metricas()['fuentes']['outfit'][(string) $peso];
            $this->assertSame(hash_file('sha256', FuentesCredential::rutaArchivo('outfit', $peso)), $m['sha256']);
            $this->assertSame(1000, $m['unitsPerEm']);
            $this->assertSame(1000, $m['ascent']);
            $this->assertSame(-260, $m['descent']);
        }
    }

    public function test_verify_falla_si_metricas_json_no_coincide(): void
    {
        $ruta = FuentesCredential::rutaMetricas();
        $original = file_get_contents($ruta);

        try {
            file_put_contents($ruta, str_replace('"ascent":1000', '"ascent":999', $original));
            $this->artisan('credential-flow:fuentes-metricas', ['--verify' => true])->assertFailed();
        } finally {
            file_put_contents($ruta, $original);
        }
        $this->artisan('credential-flow:fuentes-metricas', ['--verify' => true])->assertSuccessful();
    }

    public function test_generar_es_determinista_y_no_cambia_el_archivo(): void
    {
        $antes = file_get_contents(FuentesCredential::rutaMetricas());
        $this->artisan('credential-flow:fuentes-metricas')->assertSuccessful();

        $this->assertSame($antes, file_get_contents(FuentesCredential::rutaMetricas()));
    }

    // ── LectorTtf ─────────────────────────────────────────────────────────────

    public function test_el_lector_rechaza_archivos_que_no_son_truetype(): void
    {
        $this->expectException(RuntimeException::class);
        new LectorTtf('OTTO'.str_repeat("\0", 64));
    }

    public function test_el_lector_rechaza_datos_truncados(): void
    {
        $this->expectException(RuntimeException::class);
        new LectorTtf("\0\1\0\0");
    }

    public function test_el_lector_falla_con_un_archivo_inexistente(): void
    {
        $this->expectException(RuntimeException::class);
        LectorTtf::desdeArchivo(FuentesCredential::directorio().'/no-existe.ttf');
    }

    public function test_cobertura_completa_del_espanol_y_signos_habituales(): void
    {
        $texto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 ÁÉÍÓÚáéíóúÑñÜü¿?¡!«»“”‘’—–-.,;:()[]/%&@#$+=_';
        foreach (FuentesCredential::pesosReproducibles() as $peso) {
            $r = FuentesCredential::medirTexto($texto, 'outfit', $peso, 22);
            $this->assertTrue($r['soportado'], "Faltan en peso $peso: ".implode('', $r['faltantes']));
        }
    }

    // ── Paridad con el editor (vectores compartidos) ──────────────────────────

    public function test_anchos_coinciden_con_los_vectores_compartidos(): void
    {
        $v = $this->vectores();
        $this->assertNotEmpty($v['anchos']);

        foreach ($v['anchos'] as $caso) {
            $r = FuentesCredential::medirTexto($caso['texto'], $caso['familia'], $caso['peso'], $caso['fontSize']);
            $this->assertEqualsWithDelta($caso['ancho'], $r['ancho'], $v['tolerancia'] + 1e-9, "«{$caso['texto']}»");
            $this->assertSame($caso['faltantes'], $r['faltantes'], "«{$caso['texto']}»");
            $this->assertSame($caso['faltantes'] === [], $r['soportado']);
        }
    }

    public function test_lineas_base_coinciden_con_los_vectores_compartidos(): void
    {
        $v = $this->vectores();

        foreach ($v['lineasBase'] as $caso) {
            $lb = FuentesCredential::lineaBase($caso['y'], $caso['alto'], $caso['familia'], $caso['peso'], $caso['fontSize']);
            $this->assertEqualsWithDelta($caso['lineaBase'], $lb, $v['tolerancia'] + 1e-9);
        }
    }

    public function test_el_texto_se_normaliza_a_nfc_y_no_hay_kerning(): void
    {
        $a = FuentesCredential::medirTexto("Cafe\u{0301}", 'outfit', 700, 22);
        $b = FuentesCredential::medirTexto("Caf\u{00E9}", 'outfit', 700, 22);
        $this->assertSame($b['ancho'], $a['ancho']);

        $av = FuentesCredential::medirTexto('AV', 'outfit', 700, 100)['ancho'];
        $suma = FuentesCredential::medirTexto('A', 'outfit', 700, 100)['ancho'] + FuentesCredential::medirTexto('V', 'outfit', 700, 100)['ancho'];
        $this->assertEqualsWithDelta($suma, $av, 1e-9);
    }

    public function test_un_caracter_ausente_no_se_estima_se_reporta(): void
    {
        $r = FuentesCredential::medirTexto('Hola 日本', 'outfit', 400, 22);

        $this->assertFalse($r['soportado']);
        $this->assertSame(['日', '本'], $r['faltantes']);
    }

    public function test_los_controles_no_estan_soportados(): void
    {
        $this->assertFalse(FuentesCredential::medirTexto("a\tb", 'outfit', 400, 22)['soportado']);
    }

    public function test_sin_metricas_para_fuentes_heredadas(): void
    {
        $this->expectException(RuntimeException::class);
        FuentesCredential::metricasDe('Figtree', 700);
    }

    // ── Catálogo y validación ─────────────────────────────────────────────────

    public function test_catalogo_para_el_editor(): void
    {
        $f = DisenoSchema::paraEditor()['fuentes'];

        $this->assertSame(['familia' => 'outfit', 'peso' => 700], $f['porDefecto']);
        $this->assertSame(['outfit', 'Figtree', 'Arial', 'sans-serif'], array_column($f['familias'], 'valor'));
        $this->assertSame([false, true, true, true], array_column($f['familias'], 'heredada'));
        $this->assertSame([300, 400, 500, 600, 700, 800], $f['familias'][0]['pesos']);
        $this->assertSame(1, DisenoSchema::paraEditor()['version']);
    }

    public static function combinaciones(): array
    {
        return [
            'outfit 300' => ['outfit', 300, true],
            'outfit 800' => ['outfit', 800, true],
            'outfit 350' => ['outfit', 350, false],
            'outfit 900' => ['outfit', 900, false],
            'Outfit con mayúscula' => ['Outfit', 700, false],
            'Figtree heredada' => ['Figtree', 700, true],
            'Arial heredada' => ['Arial', 400, true],
            'sans-serif heredada' => ['sans-serif', 300, true],
            'Neutraface' => ['Neutraface', 700, false],
            'Comic Sans' => ['Comic Sans MS', 400, false],
        ];
    }

    #[DataProvider('combinaciones')]
    public function test_combinacion_familia_peso(string $familia, int $peso, bool $valida): void
    {
        $this->assertSame($valida, FuentesCredential::combinacionValida($familia, $peso));
    }

    #[DataProvider('combinaciones')]
    public function test_al_guardar_se_valida_familia_y_peso(string $familia, int $peso, bool $valida): void
    {
        $p = $this->crearPlantilla();
        $r = $this->guardar($p, [$this->elemento(['fontFamily' => $familia, 'fontWeight' => $peso])]);

        if ($valida) {
            $r->assertRedirect(route('credential-flow.plantillas.editor', $p));
            $e = $p->fresh()->diseno['elements'][0];
            $this->assertSame([$familia, $peso], [$e['fontFamily'], $e['fontWeight']]);
        } else {
            $r->assertUnprocessable();
            $this->assertNull($p->fresh()->diseno);
        }
    }

    public function test_un_diseno_heredado_sigue_guardandose_y_conserva_schema_version_1(): void
    {
        $p = $this->crearPlantilla();
        $this->guardar($p, [$this->elemento(['fontFamily' => 'Figtree', 'fontWeight' => 700])])->assertRedirect();

        $this->assertSame('Figtree', $p->fresh()->diseno['elements'][0]['fontFamily']);
        $this->assertSame(1, DisenoSchema::VERSION);
    }

    public function test_la_cobertura_de_caracteres_la_aplica_el_editor_no_el_request(): void
    {
        // Decisión documentada: el backend valida familia+peso, no glifos. El editor bloquea el
        // guardado y el generador de PDF volverá a comprobar con medirTexto().
        $this->assertFalse(FuentesCredential::medirTexto('日本', 'outfit', 700, 22)['soportado']);

        $p = $this->crearPlantilla();
        $this->guardar($p, [$this->elemento(['text' => '日本'])])->assertRedirect();
    }

    public function test_el_editor_recibe_el_catalogo_de_fuentes(): void
    {
        $p = $this->crearPlantilla();

        $this->actingAs($this->admin())->get(route('credential-flow.plantillas.editor', $p))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('schema.fuentes.porDefecto.familia', 'outfit')
                ->where('schema.fuentes.familias.0.valor', 'outfit'));
    }
}
