<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\ContactoQa;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Qa\AdministradorContactosQa;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CredentialFlow\Support\ArchivosParticipantes as A;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;
use ZipArchive;

/**
 * Cierre funcional: tipo de documento por confirmar, «Cupos» sin rechazar la carga, plantilla + vista previa confirmadas antes de emitir, catálogo QA completo
 * para crear bases, recorrido «siguiente paso» y una revisión de punta a punta. Datos 100 % sintéticos.
 */
class CierreFlujoTest extends EmisionesTestCase
{
    private const COMUNES = ['evento' => 'MÓDULO DE CIERRE', 'fecha' => '16 de octubre de 2026', 'intensidad_horaria' => '5 horas'];

    private const CONTACTOS = [['QA UNO PRUEBA', 'C.C. 8.000.001', 'qa.uno@example.test'], ['QA DOS PRUEBA', 'C.C. 8.000.002', 'qa.dos@example.test'], ['QA TRES PRUEBA', 'C.C. 8.000.003', 'qa.tres@example.test']];

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

    private function sembrarCatalogo(int $cuantos = 3): void
    {
        foreach (array_slice(self::CONTACTOS, 0, $cuantos) as [$n, $d, $c]) {
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => $n, 'documento' => $d, 'documento_clave' => Texto::claveDocumento($d), 'correo' => $c, 'correo_normalizado' => $c, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function importar(string $csv, string $nombre = 'Base cierre'): Lote
    {
        return app(ImportadorParticipantes::class)->importar(UploadedFile::fake()->createWithContent('b.csv', $csv), $this->plantillaLista(), $nombre, null, self::COMUNES);
    }

    /** Plantilla de pruebas internas; con `$de`, queda asociada como la variante QA de esa plantilla (la ÚNICA con la que salen sus pruebas). */
    private function plantillaQa(?Plantilla $de = null): Plantilla
    {
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];
        $els = array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 60 + $i * 60]), $campos, array_keys($campos));
        $els[] = $this->elemento(['type' => 'text', 'text' => 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', 'y' => 380, 'fontSize' => 18]);
        $p = $this->plantillaLista($els);
        $p->update(['nombre' => 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', 'es_plantilla_qa' => true]);
        $de?->update(['variante_qa_id' => $p->id]);

        return $p->fresh();
    }

    private function exigirVistaPrevia(): void
    {
        config(['credential_flow.emision.exige_vista_previa' => true]);
    }

    private function vistaPreviaConfirmada(Lote $lote): void
    {
        $p = $lote->participantes()->where('es_control_qa', false)->firstOrFail();
        $this->actingAs($this->admin())->get(route('credential-flow.participantes.pdf', [$lote->id, $p->id]))->assertOk();
        $this->actingAs($this->admin())->post(route('credential-flow.lotes.vista-previa.confirmar', $lote))->assertSessionHas('success');
    }

    private function flujo(Lote $lote): array
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.lotes.show', $lote))->assertOk()->viewData('page')['props']['lote']['flujo'];
    }

    // ── Tipo de documento ─────────────────────────────────────────────────────

    public function test_el_tipo_vacio_se_importa_pendiente_y_un_tipo_conocido_se_une_al_numero(): void
    {
        $lote = $this->importar("nombre_completo,tipo_documento,documento\nUNO,C.C.,9.100.001\nDOS,,9.100.002\nTRES,Pasaporte,AB123456\nCUATRO,por verificar,9.100.004\nCINCO,C.E.,C.E. 9.100.005\n");

        $p = $lote->participantes()->orderBy('id')->get()->keyBy('nombre_completo');
        $this->assertSame(['C.C. 9.100.001', true], [$p['UNO']->documento, $p['UNO']->tipo_documento_confirmado]);
        $this->assertSame(['9.100.002', false], [$p['DOS']->documento, $p['DOS']->tipo_documento_confirmado], 'Sin tipo: no se supone C.C.');
        $this->assertSame(['PASAPORTE AB123456', true], [$p['TRES']->documento, $p['TRES']->tipo_documento_confirmado]);
        $this->assertFalse($p['CUATRO']->tipo_documento_confirmado);
        $this->assertSame(['C.E. 9.100.005', true], [$p['CINCO']->documento, $p['CINCO']->tipo_documento_confirmado], 'Si el documento ya trae su tipo no se duplica');
    }

    public function test_un_tipo_desconocido_es_error_de_la_fila_y_el_vacio_solo_avisa(): void
    {
        $r = app(ImportadorParticipantes::class)->validar(UploadedFile::fake()->createWithContent('b.csv', "nombre_completo,tipo_documento,documento\nUNO,Licencia,9.100.011\nDOS,,9.100.012\n"), $this->plantillaLista(), self::COMUNES);

        $this->assertFalse($r->valido());
        $this->assertSame(['TIPO_DOCUMENTO_INVALIDO'], array_values(array_unique(array_map(fn ($e) => $e->codigo, $r->errores))));
        $this->assertContains('TIPO_DOCUMENTO_SIN_CONFIRMAR', array_map(fn ($a) => $a->codigo, $r->avisos));

        $solo = app(ImportadorParticipantes::class)->validar(UploadedFile::fake()->createWithContent('b.csv', "nombre_completo,tipo_documento,documento\nDOS,,9.100.012\n"), $this->plantillaLista(), self::COMUNES);
        $this->assertTrue($solo->valido(), 'Un tipo sin confirmar NO rechaza la carga');
    }

    public function test_sin_la_columna_de_tipo_todo_sigue_como_antes(): void
    {
        $lote = $this->importar("nombre_completo,documento\nUNO,9.100.021\n");

        $this->assertTrue($lote->participantes()->first()->tipo_documento_confirmado);
    }

    public function test_un_participante_con_tipo_pendiente_no_se_emite_ni_en_masa_ni_individualmente_hasta_confirmarlo(): void
    {
        $lote = $this->importar("nombre_completo,tipo_documento,documento\nUNO,C.C.,9.100.031\nDOS,,9.100.032\n");
        $dos = $lote->participantes()->where('nombre_completo', 'DOS')->firstOrFail();

        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.emision.resumen', $lote))->json('resumen');
        $this->assertSame([1, 1], [$r['pendientes'], $r['tipo_pendiente']]);

        $this->emitirPor($lote, $dos)->assertStatus(409)->assertJsonPath('error.code', 'TIPO_DOCUMENTO_PENDIENTE');
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 1);
        $this->assertSame(0, Emision::where('participante_id', $dos->id)->count());

        // Se confirma el tipo desde el formulario (documento con su tipo) y entonces sí.
        $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote->id, $dos->id]), ['nombre_completo' => 'DOS', 'documento' => 'C.C. 9.100.032', 'tipo_documento_pendiente' => false])->assertSessionHasNoErrors();
        $this->assertTrue($dos->fresh()->tipo_documento_confirmado);
        $this->emitirPor($lote, $dos->fresh())->assertCreated();
    }

    public function test_editar_otros_datos_no_cambia_el_estado_del_tipo_ni_la_clave_del_documento(): void
    {
        $lote = $this->importar("nombre_completo,tipo_documento,documento\nDOS,,9.100.041\n");
        $dos = $lote->participantes()->firstOrFail();
        $clave = $dos->documento_clave;

        $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote->id, $dos->id]), ['nombre_completo' => 'Dos Corregido', 'documento' => $dos->documento])->assertSessionHasNoErrors();

        $f = $dos->fresh();
        $this->assertSame([false, $clave], [$f->tipo_documento_confirmado, $f->documento_clave]);
    }

    public function test_un_participante_manual_sin_tipo_queda_por_confirmar(): void
    {
        $lote = $this->importar("nombre_completo,documento\nUNO,C.C. 9.100.051\n");

        $this->actingAs($this->admin())->post(route('credential-flow.participantes.store', $lote->id), ['nombre_completo' => 'Manual Sin Tipo', 'documento' => '9.100.052', 'tipo_documento_pendiente' => true])->assertSessionHasNoErrors();

        $this->assertFalse(Participante::where('nombre_completo', 'MANUAL SIN TIPO')->firstOrFail()->tipo_documento_confirmado);
    }

    // ── Plantilla y vista previa ──────────────────────────────────────────────

    public function test_no_se_emite_oficialmente_sin_vista_previa_confirmada(): void
    {
        $this->exigirVistaPrevia();
        $lote = $this->importar("nombre_completo,documento\nUNO,C.C. 9.100.061\nDOS,C.C. 9.100.062\n");
        $uno = $lote->participantes()->firstOrFail();

        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409)->assertJsonPath('error.code', 'VISTA_PREVIA_PENDIENTE');
        $this->emitirPor($lote, $uno)->assertStatus(409)->assertJsonPath('error.code', 'VISTA_PREVIA_PENDIENTE');
        $this->assertSame(0, Emision::count());

        // Confirmar sin haber abierto la vista previa tampoco vale.
        $this->actingAs($this->admin())->post(route('credential-flow.lotes.vista-previa.confirmar', $lote))->assertSessionHas('error');
        $this->assertNull($lote->fresh()->vista_previa_confirmada_at);

        $this->vistaPreviaConfirmada($lote);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 2);
    }

    public function test_cambiar_el_diseno_o_la_plantilla_descarta_la_confirmacion(): void
    {
        $this->exigirVistaPrevia();
        $lote = $this->importar("nombre_completo,documento\nUNO,C.C. 9.100.071\n");
        $this->vistaPreviaConfirmada($lote);

        // Se edita el diseño de la plantilla después de confirmar: hay que revisar de nuevo.
        $plantilla = $lote->plantilla;
        $diseno = $plantilla->diseno;
        $diseno['elements'][0]['fontSize'] = 30;
        $plantilla->update(['diseno' => $diseno]);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote->fresh()))->assertStatus(409)->assertJsonPath('error.code', 'VISTA_PREVIA_PENDIENTE');

        $this->vistaPreviaConfirmada($lote->fresh());
        $otra = $this->plantillaLista();
        $this->actingAs($this->admin())->put(route('credential-flow.lotes.plantilla', $lote), ['plantilla_id' => $otra->id])->assertSessionHas('success');
        $this->assertSame([$otra->id, null], [$lote->fresh()->plantilla_id, $lote->fresh()->vista_previa_confirmada_at]);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote->fresh()))->assertStatus(409)->assertJsonPath('error.code', 'VISTA_PREVIA_PENDIENTE');
    }

    public function test_la_plantilla_no_se_cambia_con_emisiones_ni_a_una_de_pruebas_ni_sin_diseno(): void
    {
        $lote = $this->importar("nombre_completo,documento\nUNO,C.C. 9.100.081\nDOS,C.C. 9.100.082\n");
        $sinDiseno = $this->crearPlantilla(['nombre' => 'Sin diseño']);
        $qa = $this->plantillaQa();
        $original = $lote->plantilla_id;

        $this->actingAs($this->admin())->put(route('credential-flow.lotes.plantilla', $lote), ['plantilla_id' => $sinDiseno->id])->assertSessionHas('error');
        $this->actingAs($this->admin())->put(route('credential-flow.lotes.plantilla', $lote), ['plantilla_id' => $qa->id])->assertSessionHas('error');

        $this->emitirPor($lote, $lote->participantes()->firstOrFail())->assertCreated();
        $otra = $this->plantillaLista();
        $this->actingAs($this->admin())->put(route('credential-flow.lotes.plantilla', $lote), ['plantilla_id' => $otra->id])->assertSessionHas('error');
        $this->assertSame($original, $lote->fresh()->plantilla_id);
        $this->actingAs($this->comercial())->put(route('credential-flow.lotes.plantilla', $lote), ['plantilla_id' => $otra->id])->assertForbidden();
    }

    public function test_una_base_con_emisiones_previas_sigue_emitiendo_sin_reconfirmar_y_los_qa_no_dependen_de_la_confirmacion(): void
    {
        $lote = $this->importar("nombre_completo,documento\nUNO,C.C. 9.100.091\nDOS,C.C. 9.100.092\n");
        $this->sembrarCatalogo();
        app(ServicioQa::class)->incorporar($lote);
        $this->emitirPor($lote, $lote->participantes()->where('es_control_qa', false)->firstOrFail())->assertCreated();   // sin exigir todavía

        $this->exigirVistaPrevia();
        // Con pruebas QA pendientes y sin la variante de prueba de su plantilla, la emisión conjunta no empieza: ni los oficiales.
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409)->assertJsonPath('error.code', 'VARIANTE_QA_FALTANTE');
        $this->assertSame(1, Emision::where('lote_id', $lote->id)->count(), 'no se emitió nada más');

        $this->plantillaQa($lote->plantilla);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertCreated()->assertJsonPath('resultado.total', 3);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 1);

        // La vista previa de un QA no cuenta como revisión de la plantilla oficial.
        $otra = $this->importar("nombre_completo,documento\nTRES,C.C. 9.100.093\n", 'Otra');
        app(ServicioQa::class)->incorporar($otra);
        $qa = $otra->participantes()->where('es_control_qa', true)->firstOrFail();
        $this->actingAs($this->admin())->get(route('credential-flow.participantes.pdf', [$otra->id, $qa->id]))->assertOk();
        $this->assertNull($otra->fresh()->vista_previa_generada_at);
    }

    public function test_el_recorrido_indica_el_siguiente_paso_y_lo_que_falta(): void
    {
        $this->exigirVistaPrevia();
        $lote = $this->importar("nombre_completo,tipo_documento,documento\nUNO,C.C.,9.100.101\nDOS,,9.100.102\n");

        $f = $this->flujo($lote);
        $this->assertSame('evento', $f['siguiente']['clave'], 'Primero el evento');
        $this->assertContains('Asociar la base a un evento.', $f['faltan']);
        $this->assertSame(['evento', 'importar', 'revisar', 'plantilla', 'vista_previa', 'emitir', 'descargar'], array_column($f['pasos'], 'clave'));

        $evento = EventoCertificacion::create(['nombre' => 'Evento', 'nombre_normalizado' => 'evento', 'anio' => 2026, 'estado' => 'activo', 'origen' => 'credential_flow']);
        $lote->update(['evento_id' => $evento->id]);
        $f = $this->flujo($lote->fresh());
        $this->assertSame(['vista_previa', 'abrir_vista_previa'], [$f['siguiente']['clave'], $f['siguiente']['accion']['tipo']]);
        $this->assertTrue(collect($f['faltan'])->contains(fn ($m) => str_contains($m, 'tipo de documento por confirmar')));

        $p = $lote->participantes()->firstOrFail();
        $this->actingAs($this->admin())->get(route('credential-flow.participantes.pdf', [$lote->id, $p->id]))->assertOk();
        $this->assertSame('confirmar_vista_previa', $this->flujo($lote->fresh())['siguiente']['accion']['tipo']);

        $this->actingAs($this->admin())->post(route('credential-flow.lotes.vista-previa.confirmar', $lote));
        $f = $this->flujo($lote->fresh());
        $this->assertSame('emitir', $f['siguiente']['clave']);
        $this->assertSame('atencion', collect($f['pasos'])->firstWhere('clave', 'revisar')['estado'], 'Revisar sigue visible mientras haya tipos por confirmar');

        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();
        $this->assertSame('revisar', $this->flujo($lote->fresh())['siguiente']['clave'], 'Quedan por resolver los tipos de documento');
    }

    // ── Catálogo QA ───────────────────────────────────────────────────────────

    public function test_con_el_catalogo_incompleto_la_creacion_de_la_base_se_detiene_sin_crear_nada(): void
    {
        config(['credential_flow.qa.exigir_catalogo' => true, 'credential_flow.qa.contactos_esperados' => 8]);
        $this->sembrarCatalogo(3);
        $plantilla = $this->plantillaLista();
        $campos = ['plantilla_id' => $plantilla->id, 'nombre' => 'Base bloqueada', 'archivo' => A::subir("nombre_completo,documento\nUNO,C.C. 9.100.111\n", 'b.csv')] + self::COMUNES;

        $this->actingAs($this->admin())->post(route('credential-flow.lotes.validar'), $campos, ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('error.code', 'QA_CATALOGO_INCOMPLETO');
        $campos['archivo'] = A::subir("nombre_completo,documento\nUNO,C.C. 9.100.111\n", 'b.csv');
        $this->actingAs($this->admin())->post(route('credential-flow.lotes.store'), $campos, ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('error.code', 'QA_CATALOGO_INCOMPLETO');

        $this->assertSame([0, 0], [Lote::count(), Participante::count()]);
        $prop = $this->actingAs($this->admin())->get(route('credential-flow.lotes.nuevo'))->viewData('page')['props']['qa_catalogo'];
        $this->assertFalse($prop['listo']);
    }

    public function test_con_el_catalogo_exacto_y_verificado_se_crea_y_no_duplica_los_qa(): void
    {
        config(['credential_flow.qa.exigir_catalogo' => true]);   // esperados = 3 (setUp)
        $this->sembrarCatalogo(3);
        $plantilla = $this->plantillaLista();
        $csv = "nombre_completo,documento\nUNO,C.C. 9.100.121\n";
        $campos = fn () => ['plantilla_id' => $plantilla->id, 'nombre' => 'Base completa', 'archivo' => A::subir($csv, 'b.csv')] + self::COMUNES;

        $this->actingAs($this->admin())->post(route('credential-flow.lotes.store'), $campos(), ['Accept' => 'application/json'])->assertOk();
        $lote = Lote::where('nombre', 'Base completa')->firstOrFail();
        $this->assertSame([1, 3], [$lote->participantes()->where('es_control_qa', false)->count(), $lote->participantes()->where('es_control_qa', true)->count()]);

        app(ServicioQa::class)->incorporar($lote);
        $this->assertSame(4, $lote->participantes()->count(), 'No se duplican');
    }

    public function test_un_contacto_inactivo_o_repetido_deja_el_catalogo_no_listo(): void
    {
        config(['credential_flow.qa.exigir_catalogo' => true]);
        $this->sembrarCatalogo(3);
        $admin = app(AdministradorContactosQa::class);
        $this->assertTrue($admin->catalogoListo()['listo']);

        ContactoQa::where('documento_clave', 'CC8000003')->update(['activo' => false]);
        $this->assertFalse($admin->catalogoListo()['listo']);

        ContactoQa::where('documento_clave', 'CC8000003')->update(['activo' => true, 'correo_normalizado' => 'qa.uno@example.test', 'correo' => 'qa.uno@example.test']);
        $this->assertFalse($admin->catalogoListo()['listo'], 'Correo repetido entre contactos');
    }

    // ── Revisión de punta a punta ─────────────────────────────────────────────

    public function test_recorrido_completo_con_datos_de_prueba(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);   // el recorrido hace más de 6 acciones por minuto, a propósito
        config(['credential_flow.qa.exigir_catalogo' => true]);
        $this->exigirVistaPrevia();
        $this->sembrarCatalogo(3);
        $plantilla = $this->plantillaLista();
        $this->plantillaQa($plantilla);
        $admin = $this->admin();

        // 1. Evento primero, luego importar (columna de tipo vacía en una persona; «Cupos» en otra).
        $this->actingAs($admin)->post(route('credential-flow.eventos.store'), ['nombre' => 'Módulo de cierre', 'fecha' => '16 de octubre de 2026'])->assertRedirect();
        $evento = EventoCertificacion::firstOrFail();
        $csv = "nombre_completo,tipo_documento,documento,correo,elegibilidad\n"
            ."ANA UNO,C.C.,9.700.001,errado@example.test,elegible\n"
            ."BETO DOS,,9.700.002,beto@example.test,elegible\n"
            ."CARLA TRES,C.C.,9.700.003,carla@example.test,Cupos\n"
            ."DIEGO CUATRO,C.C.,9.700.004,diego@example.test,elegible\n";
        $r = $this->actingAs($admin)->post(route('credential-flow.lotes.store'), ['plantilla_id' => $plantilla->id, 'nombre' => 'Base cierre', 'evento_id' => $evento->id, 'archivo' => A::subir($csv, 'b.csv')] + self::COMUNES, ['Accept' => 'application/json'])->assertOk();
        $lote = Lote::findOrFail(basename($r->json('redirect')));

        // 2. Se incorporan los 3 QA sin duplicar; «Cupos» y el tipo vacío quedan pendientes, no descartados.
        $this->assertSame([4, 3], [$lote->participantes()->where('es_control_qa', false)->count(), $lote->participantes()->where('es_control_qa', true)->count()]);
        $this->assertSame($evento->id, $lote->evento_id);
        app(ServicioQa::class)->incorporar($lote);
        $this->assertSame(7, $lote->participantes()->count());
        $por = fn (string $n) => $lote->participantes()->where('nombre_completo', $n)->firstOrFail();
        $this->assertSame('pendiente', $por('CARLA TRES')->elegibilidad);
        $this->assertFalse($por('BETO DOS')->tipo_documento_confirmado);

        // 3. Sin vista previa confirmada no se emite.
        $this->actingAs($admin)->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409)->assertJsonPath('error.code', 'VISTA_PREVIA_PENDIENTE');

        // 4. Corregir el correo de ANA; validar el tipo de BETO.
        $ana = $por('ANA UNO');
        $this->actingAs($admin)->put(route('credential-flow.participantes.update', [$lote->id, $ana->id]), ['nombre_completo' => 'ANA UNO', 'documento' => $ana->documento, 'correo' => 'ana.correcta@example.test'])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('ana.correcta@example.test', $ana->fresh()->correo_normalizado);
        $beto = $por('BETO DOS');
        $this->actingAs($admin)->put(route('credential-flow.participantes.update', [$lote->id, $beto->id]), ['nombre_completo' => 'BETO DOS', 'documento' => 'C.C. 9.700.002', 'tipo_documento_pendiente' => false])->assertSessionHasNoErrors();

        // 5. Vista previa revisada y plantilla confirmada → un clic: los oficiales SOLO de elegibles con tipo confirmado y, aparte, las pruebas QA.
        $this->vistaPreviaConfirmada($lote);
        $this->actingAs($admin)->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 3)->assertJsonPath('qa.total', 3)->assertJsonPath('qa_error', null);
        $reales = $lote->participantes()->where('es_control_qa', false)->select('id');
        $this->assertSame(['ANA UNO', 'BETO DOS', 'DIEGO CUATRO'], Participante::whereIn('id', Emision::where('lote_id', $lote->id)->whereIn('participante_id', $reales)->pluck('participante_id'))->orderBy('id')->pluck('nombre_completo')->all());
        $this->assertSame(0, Emision::where('participante_id', $por('CARLA TRES')->id)->count(), '«Cupos» no se emite');

        $emAna = Emision::where('participante_id', $ana->id)->firstOrFail();
        $pdfAna = $this->actingAs($admin)->get(route('credential-flow.emisiones.descargar', $emAna))->assertOk()->getContent();
        $this->assertNotContains('PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', array_column((new InspectorPdf($pdfAna))->textos(), 'texto'));

        // 6. Pruebas internas ya emitidas en el mismo clic, visiblemente marcadas; reintentarlas no duplica nada.
        $this->actingAs($admin)->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertStatus(409)->assertJsonPath('error.code', 'SIN_PENDIENTES');
        $this->assertSame(6, Emision::where('lote_id', $lote->id)->count());
        $emQa = Emision::where('lote_id', $lote->id)->whereIn('participante_id', $lote->participantes()->where('es_control_qa', true)->select('id'))->firstOrFail();
        $this->assertContains('PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', array_column((new InspectorPdf($this->actingAs($admin)->get(route('credential-flow.emisiones.descargar', $emQa))->getContent()))->textos(), 'texto'));

        // 7. Descargar: lo emitido antes no cambió y el ZIP trae solo los 3 reales.
        $this->assertSame($emAna->pdf_hash, hash('sha256', $this->actingAs($admin)->get(route('credential-flow.emisiones.descargar', $emAna))->getContent()));
        $z = new ZipArchive;
        $this->assertTrue($z->open($this->actingAs($admin)->get(route('credential-flow.lotes.zip', $lote))->assertOk()->baseResponse->getFile()->getPathname()));
        $this->assertSame(3, $z->numFiles);
        $z->close();

        // 8. Recorrido: lo único que queda por resolver es «Cupos».
        $f = $this->flujo($lote->fresh());
        $this->assertSame('revisar', $f['siguiente']['clave']);
        $this->assertTrue(collect($f['faltan'])->contains(fn ($m) => str_contains($m, 'pendiente de validación')));
    }
}
