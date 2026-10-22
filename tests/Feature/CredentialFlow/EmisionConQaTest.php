<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;
use ZipArchive;

/**
 * «Emitir certificados» genera en el MISMO flujo los oficiales y las pruebas internas de los contactos QA (con su variante «PRUEBA INTERNA»), sin mezclarlos
 * nunca, sin duplicar nada en un segundo clic y sin informar éxito si las pruebas fallan. Datos sintéticos.
 */
class EmisionConQaTest extends EmisionesTestCase
{
    private const ADVERTENCIA = 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA';

    private Plantilla $institucional;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => [
            'database/migrations/2026_10_06_100000_cf_eventos_table.php',
            'database/migrations/2026_10_06_100100_add_evento_id_to_cf_lotes_table.php',
            'database/migrations/2026_10_06_100200_add_correo_columns_to_cf_participantes_table.php',
            'database/migrations/2026_10_06_100300_cf_plantillas_legado_table.php',
            'database/migrations/2026_10_06_100400_cf_certificados_legado_table.php',
            'database/migrations/2026_10_06_100500_cf_descargas_table.php',
            'database/migrations/2026_10_06_100600_cf_correos_table.php',
            'database/migrations/2026_10_06_100700_add_plantilla_legado_to_cf_eventos_table.php',
            'database/migrations/2026_10_08_100000_cf_migraciones_corridas_table.php',
            'database/migrations/2026_10_08_100100_add_corrida_id_to_tablas_historicas.php',
            'database/migrations/2026_10_21_100000_cf_contactos_qa_y_plantilla_qa.php',
        ], '--force' => true])->assertExitCode(0);
        config(['credential_flow.qa.contactos_esperados' => 8]);

        // Como la #13: los campos del certificado y el documento SIN «Texto antes» (el dato ya trae «C.C.»).
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];
        $this->institucional = $this->plantillaLista(array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 80 + $i * 60, 'fontSize' => 16]), $campos, array_keys($campos)));
        for ($i = 1; $i <= 8; $i++) {
            $doc = (string) (70000000 + $i);   // los contactos QA guardan solo el número
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA SINTÉTICO {$i}", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc),
                'correo' => "qa{$i}@example.test", 'correo_normalizado' => "qa{$i}@example.test", 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function base(int $reales = 3, bool $conQa = true): Lote
    {
        $lote = $this->loteCon(0, $this->institucional);
        for ($i = 1; $i <= $reales; $i++) {
            $this->participante($lote, "Persona Real {$i}", 'C.C. '.(40343230 + $i));
        }
        if ($conQa) {
            app(ServicioQa::class)->incorporar($lote);
        }

        return $lote->fresh();
    }

    private function variante(): Plantilla
    {
        $this->artisan('credential-flow:plantilla-variante-qa', ['origen' => $this->institucional->id, '--ejecutar' => true])->assertExitCode(0);

        return Plantilla::query()->findOrFail($this->institucional->fresh()->variante_qa_id);
    }

    private function textos(Emision $e): array
    {
        return array_column((new InspectorPdf($this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $e))->assertOk()->getContent()))->textos(), 'texto');
    }

    // ── Variante de PRUEBA INTERNA ────────────────────────────────────────────

    public function test_la_variante_qa_copia_el_diseno_marca_prueba_interna_y_antepone_cc_al_documento(): void
    {
        $antes = [$this->institucional->fresh()->diseno, $this->institucional->fresh()->hash_sha256];
        $n = Plantilla::count();
        $this->artisan('credential-flow:plantilla-variante-qa', ['origen' => $this->institucional->id])->assertExitCode(0)->expectsOutputToContain('SIMULACIÓN');
        $this->assertSame($n, Plantilla::count(), 'simular no escribe');

        $v = $this->variante();
        $this->assertTrue((bool) $v->es_plantilla_qa);
        $this->assertSame('PRUEBA INTERNA — '.$this->institucional->nombre, $v->nombre);
        $this->assertSame($this->institucional->hash_sha256, $v->hash_sha256, 'mismo PDF base');
        $doc = collect($v->diseno['elements'])->firstWhere('field', 'documento');
        $this->assertSame('C.C. ', $doc['prefix']);
        $this->assertTrue(collect($v->diseno['elements'])->contains(fn ($e) => ($e['text'] ?? '') === self::ADVERTENCIA));
        $this->assertTrue(ServicioQa::tieneAdvertenciaVisible($v));
        $this->assertSame($antes, [$this->institucional->fresh()->diseno, $this->institucional->fresh()->hash_sha256], 'el original no cambia');

        // Una segunda variante no se crea.
        $this->artisan('credential-flow:plantilla-variante-qa', ['origen' => $this->institucional->id, '--ejecutar' => true])->assertExitCode(1);
        $this->assertSame($n + 1, Plantilla::count());
    }

    // ── Un solo clic: oficiales + QA ──────────────────────────────────────────

    public function test_un_clic_emite_los_oficiales_y_las_pruebas_qa_por_separado_y_un_segundo_clic_no_duplica(): void
    {
        $v = $this->variante();
        $lote = $this->base();

        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();
        $this->assertSame([3, 8, null], [$r->json('resultado.total'), $r->json('qa.total'), $r->json('qa_error')]);
        $this->assertSame(3, Emision::where('plantilla_id', $this->institucional->id)->count());
        $this->assertSame(8, Emision::where('plantilla_id', $v->id)->count());

        // Segundo clic: nada pendiente, nada duplicado.
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409)->assertJsonPath('error.code', 'SIN_PENDIENTES');
        $this->assertSame(11, Emision::count());

        $oficial = Emision::where('plantilla_id', $this->institucional->id)->firstOrFail();
        $this->assertNotContains(self::ADVERTENCIA, $this->textos($oficial));
        $this->assertContains('C.C. 40.343.231', $this->textos($oficial));
        $qa = Emision::where('plantilla_id', $v->id)->firstOrFail();
        $this->assertContains(self::ADVERTENCIA, $this->textos($qa));
        $this->assertContains('C.C. 70000001', $this->textos($qa), 'la variante antepone «C.C.» al número del contacto QA');

        // ZIP administrativo: solo los 3 oficiales.
        $z = new ZipArchive;
        $this->assertTrue($z->open($this->actingAs($this->admin())->get(route('credential-flow.lotes.zip', $lote))->assertOk()->baseResponse->getFile()->getPathname()));
        $this->assertSame(3, $z->numFiles);
        $z->close();
    }

    public function test_si_fallan_las_pruebas_qa_no_se_informa_exito_y_se_reintentan_solas_sin_tocar_los_oficiales(): void
    {
        $lote = $this->base();   // todavía no hay plantilla de prueba designada

        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();
        $this->assertSame(3, $r->json('resultado.total'));
        $this->assertNull($r->json('qa'));
        $this->assertSame('PLANTILLA_NO_DISPONIBLE', $r->json('qa_error.code'));
        $oficiales = Emision::orderBy('id')->pluck('id')->all();
        $this->assertCount(3, $oficiales);

        $this->variante();
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertCreated()->assertJsonPath('resultado.total', 8);
        $this->assertSame($oficiales, Emision::where('plantilla_id', $this->institucional->id)->orderBy('id')->pluck('id')->all(), 'los oficiales no se reemiten');
        $this->assertSame(11, Emision::count());
    }

    public function test_una_base_ya_emitida_solo_completa_sus_qa_faltantes(): void
    {
        $lote = $this->base(17);
        app(EmisorLote::class)->emitirPendientes($lote, null);   // como la base #14: 17 oficiales ya emitidos, 0 QA
        $oficiales = Emision::orderBy('id')->get(['id', 'version', 'pdf_hash'])->toArray();
        $this->assertCount(17, $oficiales);
        $this->variante();

        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();
        $this->assertNull($r->json('resultado'), 'no se reemite ningún oficial');
        $this->assertSame(8, $r->json('qa.total'));
        $this->assertSame($oficiales, Emision::where('plantilla_id', $this->institucional->id)->orderBy('id')->get(['id', 'version', 'pdf_hash'])->toArray());

        $resumen = app(EmisorLote::class)->resumen($lote->fresh());
        $this->assertSame([17, 8, 0], [$resumen['vigentes'], $resumen['qa']['vigentes'], $resumen['qa']['pendientes']], 'métricas separadas');
    }

    public function test_sin_plantilla_de_prueba_y_sin_oficiales_pendientes_es_un_error_claro_sin_emitir_nada(): void
    {
        $lote = $this->base(2);
        app(EmisorLote::class)->emitirPendientes($lote, null);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409)->assertJsonPath('error.code', 'PLANTILLA_NO_DISPONIBLE');
        $this->assertSame(2, Emision::count());
    }

    public function test_una_base_sin_contactos_qa_emite_solo_los_oficiales_como_siempre(): void
    {
        $this->variante();
        $lote = $this->base(2, false);
        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();
        $this->assertSame([2, null, null], [$r->json('resultado.total'), $r->json('qa'), $r->json('qa_error')]);
    }
}
