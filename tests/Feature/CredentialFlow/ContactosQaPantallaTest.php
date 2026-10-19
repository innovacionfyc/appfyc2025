<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\ContactoQa;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Pantalla «Contactos QA» y estado operativo de las bases: verificar, corregir con motivo y confirmación, activar/desactivar, inicializar solo si las bases QA
 * oficiales coinciden y reordenar bases sin tocar participantes ni certificados. Datos 100 % sintéticos.
 */
class ContactosQaPantallaTest extends EmisionesTestCase
{
    private const COMUNES = ['evento' => 'Taller QA pantalla', 'fecha' => '6 de octubre de 2026', 'intensidad_horaria' => '4 horas'];

    private const CONTACTOS = [
        ['QA UNO PRUEBA', 'C.C. 8.000.001', 'qa.uno@example.test'],
        ['QA DOS PRUEBA', 'C.C. 8.000.002', 'qa.dos@example.test'],
        ['QA TRES PRUEBA', 'C.C. 8.000.003', 'qa.tres@example.test'],
    ];

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
        config(['credential_flow.qa.contactos_esperados' => 3]);
    }

    private function sembrar(): void
    {
        foreach (self::CONTACTOS as [$n, $d, $c]) {
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => $n, 'documento' => $d, 'documento_clave' => Texto::claveDocumento($d), 'correo' => $c, 'correo_normalizado' => $c, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function base(string $csv, string $nombre = 'Base'): Lote
    {
        return app(ImportadorParticipantes::class)->importar(UploadedFile::fake()->createWithContent('b.csv', $csv), $this->plantillaLista(), $nombre, null, self::COMUNES);
    }

    private function baseQa(string $nombre = 'Base QA oficial'): Lote
    {
        $csv = "nombre_completo,documento,correo\n".implode("\n", array_map(fn ($c) => implode(',', $c), self::CONTACTOS))."\n";
        $lote = $this->base($csv, $nombre);
        Participante::where('lote_id', $lote->id)->update(['es_control_qa' => true]);

        return $lote;
    }

    private function props(array $query = []): array
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.qa.contactos', $query))->assertOk()->viewData('page')['props'];
    }

    private function corregir(ContactoQa $c, array $datos)
    {
        return $this->actingAs($this->admin())->put(route('credential-flow.qa.contactos.update', $c), array_merge([
            'nombre_completo' => $c->nombre_completo, 'documento' => $c->documento, 'correo' => $c->correo, 'motivo' => 'Se corrige según el listado del equipo.', 'confirmo' => true,
        ], $datos));
    }

    // ── Listado y verificación ────────────────────────────────────────────────

    public function test_lista_los_contactos_con_su_verificacion_y_las_bases_donde_estan(): void
    {
        $this->sembrar();
        $lote = $this->baseQa();

        $p = $this->props();
        $this->assertSame([3, 3, 3], [$p['resumen']['total'], $p['resumen']['activos'], $p['resumen']['verificados']]);
        $uno = collect($p['contactos'])->firstWhere('documento', 'C.C. 8.000.001');
        $this->assertTrue($uno['verificado']);
        $this->assertSame([['lote_id' => $lote->id, 'nombre' => 'Base QA oficial', 'evento' => 'Taller QA pantalla', 'coincide' => true]], $uno['bases']);
    }

    public function test_marca_lo_que_no_coincide_sin_corregirlo_solo(): void
    {
        $this->sembrar();
        $lote = $this->baseQa();
        Participante::where('lote_id', $lote->id)->where('documento_clave', 'CC8000002')->update(['correo_normalizado' => 'otro@example.test', 'correo' => 'otro@example.test']);

        $dos = collect($this->props()['contactos'])->firstWhere('documento', 'C.C. 8.000.002');

        $this->assertFalse($dos['verificado']);
        $this->assertFalse($dos['bases'][0]['coincide']);
        $this->assertNotEmpty($dos['avisos']);
        $this->assertSame('otro@example.test', Participante::where('lote_id', $lote->id)->where('documento_clave', 'CC8000002')->value('correo'), 'La base no se toca');
    }

    public function test_detecta_correo_o_documento_repetidos_entre_contactos(): void
    {
        $this->sembrar();
        DB::table('cf_contactos_qa')->where('documento_clave', 'CC8000003')->update(['correo' => 'qa.uno@example.test', 'correo_normalizado' => 'qa.uno@example.test']);

        $contactos = collect($this->props()['contactos']);

        $this->assertFalse($contactos->firstWhere('documento', 'C.C. 8.000.001')['verificado']);
        $this->assertFalse($contactos->firstWhere('documento', 'C.C. 8.000.003')['verificado']);
        $this->assertTrue($contactos->firstWhere('documento', 'C.C. 8.000.002')['verificado']);
    }

    // ── Corregir ──────────────────────────────────────────────────────────────

    public function test_corregir_exige_motivo_y_confirmacion_y_no_reescribe_las_bases(): void
    {
        $this->sembrar();
        $lote = $this->baseQa();
        $c = ContactoQa::where('documento_clave', 'CC8000001')->firstOrFail();

        $this->corregir($c, ['confirmo' => false])->assertSessionHasErrors('confirmo');
        $this->corregir($c, ['motivo' => 'corto'])->assertSessionHas('error');
        $this->assertSame('qa.uno@example.test', $c->fresh()->correo);

        $this->corregir($c, ['correo' => 'Nuevo.Uno@Example.test', 'nombre_completo' => 'Qa Uno Corregido'])->assertSessionHas('success');

        $f = $c->fresh();
        $this->assertSame(['QA UNO CORREGIDO', 'nuevo.uno@example.test'], [$f->nombre_completo, $f->correo_normalizado]);
        $fila = Participante::where('lote_id', $lote->id)->where('documento_clave', 'CC8000001')->firstOrFail();
        $this->assertSame(['QA UNO PRUEBA', 'qa.uno@example.test'], [$fila->nombre_completo, $fila->correo_normalizado], 'La base que ya lo tenía conserva su fila');

        // Una base nueva sí usa los datos corregidos.
        $nueva = $this->base("nombre_completo,documento,correo\nREAL,9.500.001,real@example.test\n", 'Nueva');
        app(ServicioQa::class)->incorporar($nueva);
        $this->assertSame('nuevo.uno@example.test', Participante::where('lote_id', $nueva->id)->where('documento_clave', 'CC8000001')->value('correo_normalizado'));
    }

    public function test_corregir_rechaza_datos_invalidos_o_repetidos(): void
    {
        $this->sembrar();
        $c = ContactoQa::where('documento_clave', 'CC8000001')->firstOrFail();

        $this->corregir($c, ['correo' => 'no-es-correo'])->assertSessionHas('error');
        $this->corregir($c, ['correo' => 'uno@example.test; dos@example.test'])->assertSessionHas('error');
        $this->corregir($c, ['correo' => 'qa.dos@example.test'])->assertSessionHas('error', 'Ya hay otro contacto con ese correo.');
        $this->corregir($c, ['documento' => 'C.C. 8.000.002'])->assertSessionHas('error', 'Ya hay otro contacto con ese documento.');
        $this->assertSame('qa.uno@example.test', $c->fresh()->correo);
    }

    public function test_la_bitacora_no_guarda_correos_ni_documentos_completos(): void
    {
        $this->sembrar();
        $c = ContactoQa::where('documento_clave', 'CC8000001')->firstOrFail();

        $this->corregir($c, ['correo' => 'nuevo.uno@example.test', 'documento' => 'C.C. 8.000.099'])->assertSessionHas('success');

        $mov = Movimiento::where('descripcion', 'Se corrigió un contacto de control (QA)')->latest('id')->firstOrFail();
        $texto = json_encode($mov->toArray());
        foreach (['qa.uno@example.test', 'nuevo.uno@example.test', '8.000.001', '8.000.099', '8000001'] as $dato) {
            $this->assertStringNotContainsString($dato, $texto);
        }
        $this->assertStringContainsString('qa***@example.test', $texto);
        $this->assertContains('correo', $mov->metadata['campos']);
    }

    // ── Activar / desactivar ──────────────────────────────────────────────────

    public function test_desactivar_exige_confirmacion_y_el_contacto_ya_no_entra_a_bases_nuevas(): void
    {
        $this->sembrar();
        $c = ContactoQa::where('documento_clave', 'CC8000002')->firstOrFail();

        $url = route('credential-flow.qa.contactos.activo', $c);
        $this->actingAs($this->admin())->put($url, ['activo' => false, 'motivo' => 'Ya no trabaja en el equipo.'])->assertSessionHasErrors('confirmo');
        $this->actingAs($this->admin())->put($url, ['activo' => false, 'motivo' => 'corto', 'confirmo' => true])->assertSessionHas('error');
        $this->assertTrue((bool) $c->fresh()->activo);

        $this->actingAs($this->admin())->put($url, ['activo' => false, 'motivo' => 'Ya no trabaja en el equipo.', 'confirmo' => true])->assertSessionHas('success');
        $this->assertFalse((bool) $c->fresh()->activo);
        $this->assertNotNull(Movimiento::where('descripcion', 'Se desactivó un contacto de control (QA)')->first());

        $nueva = $this->base("nombre_completo,documento,correo\nREAL,9.500.011,real@example.test\n");
        $this->assertSame(2, app(ServicioQa::class)->incorporar($nueva)['creados']);
        $this->assertSame(0, Participante::where('lote_id', $nueva->id)->where('documento_clave', 'CC8000002')->count());

        $this->actingAs($this->admin())->put($url, ['activo' => true, 'motivo' => 'Vuelve al equipo de pruebas.', 'confirmo' => true])->assertSessionHas('success');
        $this->assertTrue((bool) $c->fresh()->activo);
    }

    public function test_solo_administradores_acceden_y_modifican(): void
    {
        $this->sembrar();
        $c = ContactoQa::firstOrFail();

        $this->get(route('credential-flow.qa.contactos'))->assertRedirect();
        $this->actingAs($this->comercial())->get(route('credential-flow.qa.contactos'))->assertForbidden();
        $this->actingAs($this->comercial())->put(route('credential-flow.qa.contactos.update', $c), ['nombre_completo' => 'X', 'documento' => '1', 'correo' => 'x@example.test', 'motivo' => 'Intento no autorizado.', 'confirmo' => true])->assertForbidden();
        $this->actingAs($this->comercial())->put(route('credential-flow.qa.contactos.activo', $c), ['activo' => false, 'motivo' => 'Intento no autorizado.', 'confirmo' => true])->assertForbidden();
        $this->actingAs($this->comercial())->post(route('credential-flow.qa.contactos.inicializar'), ['bases' => [1], 'motivo' => 'Intento no autorizado.', 'confirmo' => true])->assertForbidden();
        $this->assertTrue((bool) $c->fresh()->activo);
    }

    // ── Inicialización segura ─────────────────────────────────────────────────

    public function test_inicializa_solo_si_las_bases_oficiales_contienen_los_mismos_contactos(): void
    {
        $a = $this->baseQa('QA oficial A');
        $b = $this->baseQa('QA oficial B');

        $p = $this->props(['bases' => [$a->id, $b->id]]);
        $this->assertTrue($p['inicializacion']['disponible']);
        $this->assertSame([$a->id, $b->id], array_column($p['inicializacion']['candidatas'], 'id'));
        $this->assertTrue($p['inicializacion']['verificacion']['ok']);

        $this->actingAs($this->admin())->post(route('credential-flow.qa.contactos.inicializar'), ['bases' => [$a->id, $b->id], 'motivo' => 'Bases QA oficiales verificadas.', 'confirmo' => true])->assertSessionHas('success');

        $this->assertSame(3, ContactoQa::count());
        $this->assertSame(['CC8000001', 'CC8000002', 'CC8000003'], ContactoQa::orderBy('id')->pluck('documento_clave')->all());
        $this->assertNotNull(Movimiento::where('descripcion', 'Se inicializó el catálogo de contactos de control (QA) desde las bases oficiales')->first());

        // Con el catálogo ya cargado, la pantalla deja de ofrecer la inicialización.
        $this->assertFalse($this->props()['inicializacion']['disponible']);
    }

    public function test_no_inicializa_si_las_bases_difieren_tienen_reales_o_otro_numero(): void
    {
        $a = $this->baseQa('QA oficial A');
        $distinta = $this->baseQa('QA con un correo distinto');
        Participante::where('lote_id', $distinta->id)->where('documento_clave', 'CC8000001')->update(['correo' => 'otro@example.test', 'correo_normalizado' => 'otro@example.test']);
        $conReal = $this->baseQa('QA mezclada');
        $conReal->participantes()->create(['nombre_completo' => 'REAL', 'documento' => 'C.C. 9.600.001', 'documento_clave' => 'CC9600001']);
        $incompleta = $this->baseQa('QA incompleta');
        Participante::where('lote_id', $incompleta->id)->where('documento_clave', 'CC8000003')->delete();

        foreach ([[$a->id, $distinta->id], [$conReal->id], [$incompleta->id], [999999], []] as $bases) {
            $this->actingAs($this->admin())->post(route('credential-flow.qa.contactos.inicializar'), ['bases' => $bases ?: [0], 'motivo' => 'Bases QA oficiales verificadas.', 'confirmo' => true])->assertSessionHas('error');
        }
        $this->assertSame(0, ContactoQa::count(), 'Nada se carga si la verificación no pasa');

        $this->actingAs($this->admin())->post(route('credential-flow.qa.contactos.inicializar'), ['bases' => [$a->id], 'motivo' => 'Bases QA oficiales verificadas.'])->assertSessionHasErrors('confirmo');
        $this->assertSame(0, ContactoQa::count());
    }

    public function test_inicializar_no_modifica_ni_reemplaza_contactos_existentes(): void
    {
        $a = $this->baseQa();
        DB::table('cf_contactos_qa')->insert(['nombre_completo' => 'NOMBRE YA CARGADO', 'documento' => 'C.C. 8.000.001', 'documento_clave' => 'CC8000001', 'correo' => 'ya@example.test', 'correo_normalizado' => 'ya@example.test', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->admin())->post(route('credential-flow.qa.contactos.inicializar'), ['bases' => [$a->id], 'motivo' => 'Completar los que faltan.', 'confirmo' => true])->assertSessionHas('success');

        $this->assertSame(3, ContactoQa::count());
        $this->assertSame(['NOMBRE YA CARGADO', 'ya@example.test'], [ContactoQa::where('documento_clave', 'CC8000001')->value('nombre_completo'), ContactoQa::where('documento_clave', 'CC8000001')->value('correo')], 'El existente no se reemplaza');
    }

    // ── Estado operativo de las bases ─────────────────────────────────────────

    public function test_las_bases_existentes_quedan_activas_y_el_filtro_las_separa_sin_tocarlas(): void
    {
        $uno = $this->loteCon(2);
        $dos = $this->loteCon(1);
        $this->actingAs($this->admin());
        $antes = DB::table('cf_participantes')->orderBy('id')->get();

        $p = $this->get(route('credential-flow.lotes.index'))->assertOk()->viewData('page')['props'];
        $this->assertSame('activa', $p['estado']);
        $this->assertSame(2, count($p['lotes']['data']));
        $this->assertSame(['activa' => 2, 'borrador' => 0, 'archivada' => 0, 'prueba' => 0], $p['estados']);

        $this->put(route('credential-flow.lotes.estado', $dos), ['estado' => 'archivada', 'confirmo' => true])->assertSessionHas('success');

        $activas = $this->get(route('credential-flow.lotes.index'))->viewData('page')['props'];
        $this->assertSame([$uno->id], array_column($activas['lotes']['data'], 'id'));
        $arch = $this->get(route('credential-flow.lotes.index', ['estado' => 'archivada']))->viewData('page')['props'];
        $this->assertSame([$dos->id], array_column($arch['lotes']['data'], 'id'));
        $this->assertSame(['activa' => 1, 'borrador' => 0, 'archivada' => 1, 'prueba' => 0], $arch['estados']);
        $this->assertEquals($antes, DB::table('cf_participantes')->orderBy('id')->get(), 'Ningún participante cambia');
        $this->assertNotNull(Movimiento::where('descripcion', 'Se cambió el estado operativo de una base')->first());
        $this->assertNull(Lote::withTrashed()->find($dos->id)->deleted_at, 'Archivar no elimina');
    }

    public function test_cambiar_el_estado_exige_confirmacion_y_un_valor_valido_y_no_toca_las_emisiones(): void
    {
        $lote = $this->loteCon(2);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();
        $emisiones = DB::table('cf_emisiones')->orderBy('id')->get();

        $this->put(route('credential-flow.lotes.estado', $lote), ['estado' => 'prueba'])->assertSessionHasErrors('confirmo');
        $this->put(route('credential-flow.lotes.estado', $lote), ['estado' => 'inventado', 'confirmo' => true])->assertSessionHasErrors('estado');
        $this->assertSame('activa', $lote->fresh()->estado_operativo);

        $this->put(route('credential-flow.lotes.estado', $lote), ['estado' => 'prueba', 'confirmo' => true])->assertSessionHas('success');
        $this->assertSame('prueba', $lote->fresh()->estado_operativo);
        $this->assertEquals($emisiones, DB::table('cf_emisiones')->orderBy('id')->get());
        $this->assertSame(2, Emision::where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)->count());

        $this->actingAs($this->comercial())->put(route('credential-flow.lotes.estado', $lote), ['estado' => 'activa', 'confirmo' => true])->assertForbidden();
        $this->assertSame('prueba', $lote->fresh()->estado_operativo);
    }
}
