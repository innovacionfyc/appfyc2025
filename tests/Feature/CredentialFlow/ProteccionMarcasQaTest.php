<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Support\Facades\DB;

/**
 * Protecciones mínimas de las marcas QA: la designación de una plantilla de pruebas que ya emitió certificados no se quita (sus bases dejarían de poder
 * emitir pruebas), y el marcador exigido para designarla es el MISMO que reconoce después la verificación pública en el documento emitido.
 */
class ProteccionMarcasQaTest extends EmisionesTestCase
{
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
        foreach ([1, 2] as $i) {
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA SINTÉTICO {$i}", 'documento' => (string) (70000000 + $i), 'documento_clave' => Texto::claveDocumento((string) (70000000 + $i)),
                'correo' => "qa{$i}@example.test", 'correo_normalizado' => "qa{$i}@example.test", 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function familia(): array
    {
        $institucional = $this->plantillaLista();
        $this->artisan('credential-flow:plantilla-variante-qa', ['origen' => $institucional->id, '--ejecutar' => true])->assertExitCode(0);
        $lote = $this->loteCon(1, $institucional);
        $lote->participantes()->update(['correo' => 'real1@example.test', 'correo_normalizado' => 'real1@example.test', 'correo_estado' => 'valido']);
        app(ServicioQa::class)->incorporar($lote);

        return [$lote->fresh(), Plantilla::findOrFail($institucional->fresh()->variante_qa_id)];
    }

    private function emitir(Lote $lote, Participante $p): Emision
    {
        $this->emitirPor($lote, $p)->assertCreated();

        return Emision::where('participante_id', $p->id)->where('estado', Emision::EMITIDA)->firstOrFail();
    }

    private function qa(Lote $lote, int $n = 1): Participante
    {
        return Participante::where('lote_id', $lote->id)->where('correo_normalizado', "qa{$n}@example.test")->firstOrFail();
    }

    public function test_los_contactos_qa_se_siguen_incorporando_y_emitiendo_pruebas(): void
    {
        [$lote, $variante] = $this->familia();
        $this->emitir($lote, $this->qa($lote, 1));

        $this->assertSame(2, Participante::where('lote_id', $lote->id)->where('es_control_qa', true)->count());
        $this->assertSame($variante->id, $this->emitir($lote, $this->qa($lote, 2))->plantilla_id);
    }

    // ── Designación de la plantilla de pruebas (pantalla plantillas.qa) ───────

    public function test_no_se_quita_la_designacion_de_una_plantilla_de_pruebas_que_ya_emitio(): void
    {
        [$lote, $variante] = $this->familia();
        $this->emitir($lote, $this->qa($lote));
        $antes = DB::table('movimientos')->count();

        $this->actingAs($this->admin())->from('/admin')->put(route('credential-flow.plantillas.qa', $variante), ['es_plantilla_qa' => false])
            ->assertRedirect('/admin')->assertSessionHas('error', fn ($m) => str_contains($m, 'ya generó certificados'));

        $this->assertTrue((bool) $variante->fresh()->es_plantilla_qa);
        $this->assertSame($antes, DB::table('movimientos')->count());
        $this->assertSame($variante->id, $this->emitir($lote, $this->qa($lote, 2))->plantilla_id, 'la base sigue emitiendo pruebas');
    }

    public function test_una_plantilla_de_pruebas_sin_emisiones_si_puede_dejar_de_estar_designada(): void
    {
        [, $variante] = $this->familia();

        $this->actingAs($this->admin())->from('/admin')->put(route('credential-flow.plantillas.qa', $variante), ['es_plantilla_qa' => false])->assertSessionHas('success');

        $this->assertFalse((bool) $variante->fresh()->es_plantilla_qa);
    }

    // ── El marcador exigido es el mismo que reconoce la verificación pública ──

    public function test_para_designar_una_plantilla_qa_el_aviso_debe_ser_su_propio_texto_fijo(): void
    {
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];
        $con = function (array $extra) use ($campos): Plantilla {
            $els = array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 60 + $i * 60]), $campos, array_keys($campos));
            $els[] = $this->elemento($extra + ['y' => 400]);

            return $this->plantillaLista($els);
        };

        $mezclado = $con(['text' => 'Taller de PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA 2026']);
        $this->actingAs($this->admin())->from('/admin')->put(route('credential-flow.plantillas.qa', $mezclado), ['es_plantilla_qa' => true])->assertSessionHas('error');
        $this->assertFalse((bool) $mezclado->fresh()->es_plantilla_qa);

        $propio = $con(['text' => 'Prueba interna – sin validez académica.']);
        $this->actingAs($this->admin())->from('/admin')->put(route('credential-flow.plantillas.qa', $propio), ['es_plantilla_qa' => true])->assertSessionHas('success');
        $this->assertTrue((bool) $propio->fresh()->es_plantilla_qa);
    }

    public function test_la_variante_qa_agrega_el_marcador_aunque_el_origen_ya_diga_prueba_interna_de_otra_forma(): void
    {
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];
        $els = array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 60 + $i * 60]), $campos, array_keys($campos));
        $els[] = $this->elemento(['text' => 'Curso de PRUEBA INTERNA del área', 'y' => 400]);
        $origen = $this->plantillaLista($els);

        $this->artisan('credential-flow:plantilla-variante-qa', ['origen' => $origen->id, '--ejecutar' => true])->assertExitCode(0);

        $variante = Plantilla::findOrFail($origen->fresh()->variante_qa_id);
        $this->assertTrue((bool) $variante->es_plantilla_qa);
        $this->assertTrue(ServicioQa::tieneAdvertenciaVisible($variante));
    }
}
