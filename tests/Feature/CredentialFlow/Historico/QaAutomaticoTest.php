<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Emisiones\GeneradorZip;
use App\Support\CredentialFlow\Eventos\ServicioEventos;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CredentialFlow\Support\ArchivosParticipantes as A;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;
use ZipArchive;

/**
 * Contactos de control (QA) automáticos: entran solos y sin duplicar a cada base, marcados desde la creación; no cuentan como participantes reales ni como
 * pendientes oficiales; nunca reciben un certificado académico (solo el de la plantilla «PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA»); siguen sirviendo para probar
 * OTP y descarga; y no tocan lo ya emitido. Datos 100 % sintéticos.
 */
class QaAutomaticoTest extends PortalTestCase
{
    private const COMUNES = ['evento' => 'Taller de prueba QA', 'fecha' => '6 de octubre de 2026', 'intensidad_horaria' => '4 horas'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_21_100000_cf_contactos_qa_y_plantilla_qa.php', '--force' => true])->assertExitCode(0);
        foreach ([['QA UNO PRUEBA', 'C.C. 8.000.001', 'qa.uno@example.test'], ['QA DOS PRUEBA', 'C.C. 8.000.002', 'qa.dos@example.test'], ['QA TRES PRUEBA', 'C.C. 8.000.003', 'qa.tres@example.test']] as [$n, $d, $c]) {
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => $n, 'documento' => $d, 'documento_clave' => Texto::claveDocumento($d), 'correo' => $c, 'correo_normalizado' => $c, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function importar(string $csv, string $nombre = 'Base QA'): Lote
    {
        return app(ImportadorParticipantes::class)->importar(UploadedFile::fake()->createWithContent('base.csv', $csv), $this->plantillaLista(), $nombre, null, self::COMUNES);
    }

    /**
     * Variante de pruebas del diseño institucional: los mismos cinco campos más la advertencia como TEXTO VISIBLE del diseño (no solo en el nombre). Con `$de`,
     * queda asociada como la variante QA de la plantilla de esas bases: la ÚNICA con la que salen sus pruebas.
     *
     * @param  list<Lote>  $de
     */
    private function plantillaQa(bool $designar = true, bool $conAdvertencia = true, string $nombre = 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', array $de = []): Plantilla
    {
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];
        $els = array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 60 + $i * 60]), $campos, array_keys($campos));
        if ($conAdvertencia) {
            $els[] = $this->elemento(['type' => 'text', 'text' => 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', 'y' => 380, 'fontSize' => 18]);
        }
        $p = $this->plantillaLista($els);
        $p->update(['nombre' => $nombre, 'es_plantilla_qa' => $designar]);
        foreach ($de as $lote) {
            Plantilla::whereKey($lote->plantilla_id)->update(['variante_qa_id' => $p->id]);
        }

        return $p->fresh();
    }

    /** Textos impresos en un PDF emitido (por la ruta de descarga del administrador). */
    private function textosDelPdf(Emision $e): array
    {
        $r = $this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $e))->assertOk();

        return array_column((new InspectorPdf((string) $r->getContent()))->textos(), 'texto');
    }

    private function baseConQa(string $csv = "nombre_completo,documento,correo\nREAL UNO,9.300.001,real1@example.test\nREAL DOS,9.300.002,real2@example.test\n"): Lote
    {
        $lote = $this->importar($csv);
        app(ServicioQa::class)->incorporar($lote);

        return $lote;
    }

    public function test_incorporar_agrega_los_contactos_marcados_con_sus_correos_y_es_idempotente(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nREAL UNO,9.300.001,real1@example.test\n");

        $r = app(ServicioQa::class)->incorporar($lote);
        $this->assertSame(['creados' => 3, 'existentes' => 0, 'omitidos' => 0, 'total' => 3], $r);
        $qa = Participante::where('lote_id', $lote->id)->where('es_control_qa', true)->get();
        $this->assertCount(3, $qa);
        $this->assertSame(['valido'], $qa->pluck('correo_estado')->unique()->values()->all());
        $this->assertSame(3, DB::table('cf_correos')->whereIn('participante_id', $qa->pluck('id'))->where('es_principal', true)->count());

        $this->assertSame(['creados' => 0, 'existentes' => 3, 'omitidos' => 0, 'total' => 3], app(ServicioQa::class)->incorporar($lote));
        $this->assertSame(4, Participante::where('lote_id', $lote->id)->count(), 'Repetir no duplica');
        $this->assertFalse((bool) Participante::where('lote_id', $lote->id)->where('documento_clave', '9300001')->value('es_control_qa'), 'El participante real no se marca');
    }

    public function test_un_documento_que_ya_es_participante_real_no_se_reclasifica(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nREAL CON DOCUMENTO QA,C.C. 8.000.001,real@example.test\n");

        $r = app(ServicioQa::class)->incorporar($lote);

        $this->assertSame([2, 1], [$r['creados'], $r['omitidos']]);
        $this->assertFalse((bool) Participante::where('lote_id', $lote->id)->where('documento_clave', 'CC8000001')->value('es_control_qa'));
    }

    public function test_una_base_nueva_creada_desde_la_pantalla_incorpora_los_qa_sola(): void
    {
        $plantilla = $this->plantillaLista();
        $csv = "nombre_completo,documento,correo\nREAL UNO,9.300.011,real1@example.test\nREAL DOS,9.300.012,real2@example.test\n";
        $campos = array_merge(['plantilla_id' => $plantilla->id, 'nombre' => 'Base desde pantalla', 'descripcion' => null], self::COMUNES, ['archivo' => A::subir($csv, 'base.csv')]);

        $r = $this->actingAs($this->admin())->post(route('credential-flow.lotes.store'), $campos, ['Accept' => 'application/json'])->assertOk();

        $lote = Lote::where('nombre', 'Base desde pantalla')->firstOrFail();
        $this->assertSame([2, 3], [Participante::where('lote_id', $lote->id)->where('es_control_qa', false)->count(), Participante::where('lote_id', $lote->id)->where('es_control_qa', true)->count()]);
        $this->assertStringContainsString('3 contactos de control (QA)', session('success'));
        $this->assertNotNull($r->json('redirect'));
    }

    public function test_los_qa_no_cuentan_como_reales_ni_como_pendientes_oficiales(): void
    {
        $lote = $this->baseConQa();

        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.emision.resumen', $lote))->assertOk()->json('resumen');

        $this->assertSame([2, 2], [$r['participantes'], $r['pendientes']]);
        $this->assertSame([3, 3, 0], [$r['qa']['participantes'], $r['qa']['pendientes'], $r['qa']['vigentes']]);
        $this->assertFalse($r['qa']['plantilla_lista']);

        $idx = $this->actingAs($this->admin())->get(route('credential-flow.lotes.index'))->assertOk()->viewData('page')['props']['lotes']['data'][0];
        $this->assertSame([2, 3], [$idx['participantes'], $idx['qa']]);
    }

    public function test_la_emision_oficial_nunca_emite_a_los_qa(): void
    {
        $lote = $this->baseConQa();

        // Por la ruta de la pantalla: con QA pendientes y sin la variante de su plantilla no empieza nada (ni los oficiales).
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409)->assertJsonPath('error.code', 'VARIANTE_QA_FALTANTE');
        $this->assertSame(0, Emision::where('lote_id', $lote->id)->count());

        $r = app(EmisorLote::class)->emitirPendientes($lote, null);   // la parte OFICIAL de «Emitir certificados»
        $this->assertSame([2, 0], [$r['total'], $r['restantes']]);

        $emitidos = Emision::where('lote_id', $lote->id)->pluck('participante_id');
        $this->assertCount(2, $emitidos);
        $this->assertSame(0, Participante::whereIn('id', $emitidos)->where('es_control_qa', true)->count());
        $this->assertSame(3, Participante::where('lote_id', $lote->id)->where('es_control_qa', true)->whereDoesntHave('emisiones')->count());
    }

    public function test_emitir_pruebas_internas_usa_solo_la_plantilla_qa_y_solo_a_los_qa(): void
    {
        $lote = $this->baseConQa();
        $this->assertSame(2, app(EmisorLote::class)->emitirPendientes($lote, null)['total']);
        $reales = Emision::where('lote_id', $lote->id)->orderBy('id')->get()->map->only(['id', 'plantilla_id', 'pdf_hash', 'estado', 'codigo'])->all();

        // Sin la variante de pruebas de su plantilla: no se emite nada (ni se cae a la plantilla académica ni a otra de pruebas).
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertStatus(409)->assertJsonPath('error.code', 'VARIANTE_QA_FALTANTE');
        $this->assertSame(2, Emision::where('lote_id', $lote->id)->count());

        $qa = $this->plantillaQa(de: [$lote]);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertCreated()->assertJsonPath('resultado.total', 3);

        $emQa = Emision::where('lote_id', $lote->id)->whereIn('participante_id', Participante::where('lote_id', $lote->id)->where('es_control_qa', true)->select('id'))->get();
        $this->assertCount(3, $emQa);
        $this->assertSame([$qa->id], $emQa->pluck('plantilla_id')->unique()->values()->all());
        $this->assertStringContainsString('PRUEBA INTERNA', (string) $emQa->first()->datos_snapshot['plantilla_nombre']);
        $this->assertSame($reales, Emision::where('lote_id', $lote->id)->whereNotIn('id', $emQa->pluck('id'))->orderBy('id')->get()->map->only(['id', 'plantilla_id', 'pdf_hash', 'estado', 'codigo'])->all(), 'Lo emitido a reales no cambia');
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertStatus(409)->assertJsonPath('error.code', 'SIN_PENDIENTES');
    }

    public function test_la_emision_individual_de_un_qa_tambien_usa_la_plantilla_de_pruebas(): void
    {
        $lote = $this->baseConQa();
        $qa = Participante::where('lote_id', $lote->id)->where('es_control_qa', true)->orderBy('id')->first();

        $this->emitirPor($lote, $qa)->assertStatus(409)->assertJsonPath('error.code', 'VARIANTE_QA_FALTANTE');
        $p = $this->plantillaQa(de: [$lote]);
        $this->emitirPor($lote, $qa)->assertCreated();

        $this->assertSame($p->id, Emision::where('participante_id', $qa->id)->value('plantilla_id'));
    }

    public function test_solo_una_plantilla_con_la_advertencia_dentro_del_diseno_puede_designarse(): void
    {
        // Un nombre que dice «prueba interna» NO basta: la designación es explícita y el diseño debe llevar la advertencia impresa.
        $sinTexto = $this->plantillaQa(false, false);
        $this->actingAs($this->admin())->put(route('credential-flow.plantillas.qa', $sinTexto->id), ['es_plantilla_qa' => true])->assertSessionHas('error');
        $this->assertFalse((bool) $sinTexto->fresh()->es_plantilla_qa);

        $conTexto = $this->plantillaQa(false, true, 'Variante de pruebas del seminario');
        $this->actingAs($this->admin())->put(route('credential-flow.plantillas.qa', $conTexto->id), ['es_plantilla_qa' => true])->assertSessionHas('success');
        $this->assertTrue((bool) $conTexto->fresh()->es_plantilla_qa);

        $this->assertTrue(ServicioQa::tieneAdvertenciaVisible($conTexto));
        $this->assertFalse(ServicioQa::tieneAdvertenciaVisible($this->plantillaLista()), 'Una plantilla institucional normal no cumple');
        $this->assertSame($conTexto->id, app(ServicioQa::class)->plantilla()?->id);
    }

    public function test_una_plantilla_marcada_pero_sin_advertencia_en_el_diseno_no_emite_nada(): void
    {
        $lote = $this->baseConQa();
        $this->plantillaQa(true, false, de: [$lote]);   // designada y asociada «a la fuerza» pero sin el texto: no se puede usar

        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertStatus(409)->assertJsonPath('error.code', 'VARIANTE_QA_FALTANTE');
        $this->assertSame(0, Emision::where('lote_id', $lote->id)->count());
    }

    public function test_el_pdf_de_prueba_interna_imprime_la_advertencia_y_el_oficial_no(): void
    {
        $lote = $this->baseConQa();
        $this->plantillaQa(de: [$lote]);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('qa.total', 3);

        $porQa = fn (bool $qa) => Emision::where('lote_id', $lote->id)->whereIn('participante_id', Participante::where('lote_id', $lote->id)->where('es_control_qa', $qa)->select('id'))->get();
        foreach ($porQa(true) as $e) {
            $this->assertContains('PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', $this->textosDelPdf($e), 'La advertencia sale impresa en el PDF de prueba');
        }
        foreach ($porQa(false) as $e) {
            $this->assertNotContains('PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', $this->textosDelPdf($e), 'El PDF oficial nunca lleva la advertencia');
        }
    }

    public function test_cada_plantilla_institucional_usa_su_propia_variante_de_pruebas(): void
    {
        $lote = $this->baseConQa();
        $propia = $this->plantillaQa(true, true, 'Variante del diseño de esta base');
        $reciente = $this->plantillaQa(true, true, 'Variante genérica más reciente');
        $this->assertSame($reciente->id, app(ServicioQa::class)->plantilla()?->id, 'La última designada sigue siendo la de omisión del catálogo…');
        $this->assertNull(app(ServicioQa::class)->plantillaPara($lote), '…pero una base sin variante propia NO la usa: sus pruebas no salen con el diseño de otra capacitación');

        $this->actingAs($this->admin())->put(route('credential-flow.plantillas.variante-qa', $lote->plantilla_id), ['variante_qa_id' => $propia->id])->assertSessionHas('success');
        $this->assertSame($propia->id, app(ServicioQa::class)->plantillaPara($lote->fresh())?->id, 'La variante propia del diseño de la base manda sobre la última designada');

        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertCreated();
        $this->assertSame([$propia->id], Emision::where('lote_id', $lote->id)->pluck('plantilla_id')->unique()->values()->all());

        // Una variante que no es de pruebas (sin designar o sin advertencia) se rechaza y no se guarda.
        $normal = $this->plantillaLista();
        $this->actingAs($this->admin())->put(route('credential-flow.plantillas.variante-qa', $lote->plantilla_id), ['variante_qa_id' => $normal->id])->assertSessionHas('error');
        $this->assertSame($propia->id, Plantilla::find($lote->plantilla_id)->variante_qa_id);
    }

    public function test_el_zip_de_la_base_deja_fuera_las_pruebas_internas(): void
    {
        $lote = $this->baseConQa();
        $this->plantillaQa(de: [$lote]);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 2)->assertJsonPath('qa.total', 3);

        $r = $this->actingAs($this->admin())->get(route('credential-flow.lotes.zip', $lote))->assertOk();

        $z = new ZipArchive;
        $this->assertTrue($z->open($r->baseResponse->getFile()->getPathname()));
        $this->assertSame(2, $z->numFiles, 'Solo los 2 certificados reales');
        $z->close();
        $this->assertSame(5, Emision::where('lote_id', $lote->id)->count());
        $this->assertInstanceOf(GeneradorZip::class, app(GeneradorZip::class));
    }

    public function test_un_contacto_qa_prueba_otp_y_descarga_su_certificado_de_prueba(): void
    {
        $lote = $this->baseConQa();
        $this->plantillaQa(de: [$lote]);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertCreated();
        $em = Emision::where('lote_id', $lote->id)->whereIn('participante_id', Participante::where('documento_clave', 'CC8000001')->select('id'))->firstOrFail();

        $this->entrar('C.C. 8.000.001', 'qa.uno@example.test')->assertRedirect(route('portal.panel'));
        $this->get(route('portal.panel'))->assertOk()->assertSee('Descargar PDF');
        $pdf = $this->get(route('portal.descargar-moderno', $em->id))->assertOk();
        $this->assertSame($em->pdf_hash, hash_file('sha256', $pdf->baseResponse->getFile()->getPathname()));
    }

    public function test_incorporar_no_modifica_lo_ya_emitido(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nREAL UNO,9.300.021,real1@example.test\n");
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();
        $antes = DB::table('cf_emisiones')->orderBy('id')->get();
        $archivos = collect(Storage::disk('local')->allFiles())->mapWithKeys(fn ($f) => [$f => md5(Storage::disk('local')->get($f))])->all();

        app(ServicioQa::class)->incorporar($lote);

        $this->assertEquals($antes, DB::table('cf_emisiones')->orderBy('id')->get());
        $this->assertSame($archivos, collect(Storage::disk('local')->allFiles())->mapWithKeys(fn ($f) => [$f => md5(Storage::disk('local')->get($f))])->all());
    }

    public function test_el_boton_de_la_base_agrega_los_contactos_que_falten(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nREAL UNO,9.300.031,real1@example.test\n");

        $this->actingAs($this->admin())->post(route('credential-flow.lotes.qa.incorporar', $lote))->assertSessionHas('success', fn ($m) => str_contains($m, '3 contactos'));
        $this->actingAs($this->admin())->post(route('credential-flow.lotes.qa.incorporar', $lote))->assertSessionHas('success', fn ($m) => str_contains($m, 'ya tiene todos'));
        $this->actingAs($this->comercial())->post(route('credential-flow.lotes.qa.incorporar', $lote))->assertForbidden();
        $this->assertSame(3, Participante::where('lote_id', $lote->id)->where('es_control_qa', true)->count());
    }

    public function test_el_comando_siembra_los_contactos_desde_una_base_existente_y_solo_muestra_sin_ejecutar(): void
    {
        DB::table('cf_contactos_qa')->delete();
        $origen = $this->importar("nombre_completo,documento,correo\nCONTACTO A,7.000.001,a@example.test\nCONTACTO B,7.000.002,b@example.test\nSIN CORREO,7.000.003,\n", 'Pruebas internas existentes');

        $this->artisan('credential-flow:qa-contactos', ['--desde-lote' => $origen->id])->expectsOutputToContain('2 nuevos')->assertExitCode(0);
        $this->assertSame(0, DB::table('cf_contactos_qa')->count(), 'Vista previa: no escribe');

        $this->artisan('credential-flow:qa-contactos', ['--desde-lote' => $origen->id, '--ejecutar' => true])->expectsOutputToContain('2 nuevos')->assertExitCode(0);
        $this->assertSame(2, DB::table('cf_contactos_qa')->count());
        $this->artisan('credential-flow:qa-contactos', ['--desde-lote' => $origen->id, '--ejecutar' => true])->expectsOutputToContain('0 nuevos')->assertExitCode(0);

        $nueva = $this->importar("nombre_completo,documento,correo\nREAL,9.300.041,r@example.test\n", 'Base existente');
        $this->artisan('credential-flow:qa-contactos', ['--incorporar' => $nueva->id])->expectsOutputToContain('se agregarían 2')->assertExitCode(0);
        $this->assertSame(0, Participante::where('lote_id', $nueva->id)->where('es_control_qa', true)->count());
        $this->artisan('credential-flow:qa-contactos', ['--incorporar' => $nueva->id, '--ejecutar' => true])->assertExitCode(0);
        $this->assertSame(2, Participante::where('lote_id', $nueva->id)->where('es_control_qa', true)->count());
    }

    public function test_el_comando_exige_la_advertencia_dentro_del_diseno(): void
    {
        $p = $this->plantillaLista();

        $this->artisan('credential-flow:qa-contactos', ['--plantilla' => $p->id, '--ejecutar' => true])->assertExitCode(1);
        $this->assertFalse((bool) $p->fresh()->es_plantilla_qa);

        $p->update(['nombre' => 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA']);   // el nombre solo no basta
        $this->artisan('credential-flow:qa-contactos', ['--plantilla' => $p->id, '--ejecutar' => true])->assertExitCode(1);

        $q = $this->plantillaQa(false);
        $this->artisan('credential-flow:qa-contactos', ['--plantilla' => $q->id])->assertExitCode(0);
        $this->assertFalse((bool) $q->fresh()->es_plantilla_qa, 'Vista previa: no escribe');
        $this->artisan('credential-flow:qa-contactos', ['--plantilla' => $q->id, '--ejecutar' => true])->assertExitCode(0);
        $this->assertTrue((bool) $q->fresh()->es_plantilla_qa);
    }

    public function test_sin_contactos_configurados_crear_una_base_sigue_igual(): void
    {
        DB::table('cf_contactos_qa')->delete();
        $lote = $this->importar("nombre_completo,documento,correo\nREAL UNO,9.300.051,real1@example.test\n");

        $this->assertSame(['creados' => 0, 'existentes' => 0, 'omitidos' => 0, 'total' => 0], app(ServicioQa::class)->incorporar($lote));
        $this->assertSame(1, Participante::where('lote_id', $lote->id)->count());
    }

    public function test_una_base_que_ya_tiene_qa_solo_recibe_los_que_le_faltan(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nREAL UNO,9.300.061,real1@example.test\nQA UNO PRUEBA,C.C. 8.000.001,qa.uno@example.test\n");
        Participante::where('lote_id', $lote->id)->where('documento_clave', 'CC8000001')->update(['es_control_qa' => true]);   // ya venía marcado

        $this->assertSame(['creados' => 2, 'existentes' => 1, 'omitidos' => 0, 'total' => 3], app(ServicioQa::class)->incorporar($lote));
        $this->assertSame(3, Participante::where('lote_id', $lote->id)->where('es_control_qa', true)->count());
        $this->assertSame(1, Participante::where('lote_id', $lote->id)->where('es_control_qa', false)->count());
    }

    public function test_una_segunda_importacion_de_control_en_la_misma_base_no_duplica(): void
    {
        $lote = $this->baseConQa();
        $antes = Participante::where('lote_id', $lote->id)->count();

        for ($i = 0; $i < 3; $i++) {
            app(ServicioQa::class)->incorporar($lote);
            $this->actingAs($this->admin())->post(route('credential-flow.lotes.qa.incorporar', $lote));
        }

        $this->assertSame($antes, Participante::where('lote_id', $lote->id)->count());
        $this->assertSame(0, Participante::where('lote_id', $lote->id)->selectRaw('documento_clave, count(*) c')->groupBy('documento_clave')->having('c', '>', 1)->get()->count());
    }

    public function test_dos_modalidades_del_mismo_evento_llevan_cada_una_sus_qa_y_el_zip_del_evento_solo_trae_reales(): void
    {
        $this->artisan('migrate', ['--path' => [
            'database/migrations/2026_10_06_100000_cf_eventos_table.php',
            'database/migrations/2026_10_06_100100_add_evento_id_to_cf_lotes_table.php',
            'database/migrations/2026_10_06_100500_cf_descargas_table.php',
        ], '--force' => true]);
        $evento = EventoCertificacion::create(['nombre' => 'Taller de prueba QA', 'nombre_normalizado' => 'taller de prueba qa', 'anio' => 2026, 'estado' => 'activo', 'origen' => 'credential_flow']);
        $presencial = $this->baseConQa();
        $virtual = $this->baseConQa("nombre_completo,documento,correo\nVIRTUAL UNO,9.400.001,v1@example.test\n");
        Lote::whereIn('id', [$presencial->id, $virtual->id])->update(['evento_id' => $evento->id]);
        $this->plantillaQa(de: [$presencial, $virtual]);
        foreach ([$presencial, $virtual] as $l) {
            $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $l))->assertCreated()->assertJsonPath('qa.total', 3)->assertJsonPath('qa_error', null);   // un clic: sus oficiales y, aparte, sus QA
        }

        $resumen = app(ServicioEventos::class)->resumen($evento->fresh());
        $this->assertSame([3, 3, 6], [$resumen['reales']['participantes'], $resumen['reales']['vigentes'], $resumen['qa']['participantes']]);

        $r = $this->actingAs($this->admin())->get(route('credential-flow.eventos.zip', $evento))->assertOk();
        $z = new ZipArchive;
        $this->assertTrue($z->open($r->baseResponse->getFile()->getPathname()));
        $this->assertSame(3, $z->numFiles, 'El ZIP del evento trae solo los 3 certificados reales de las dos modalidades');
        $z->close();
    }
}
