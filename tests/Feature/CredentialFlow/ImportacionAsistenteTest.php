<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\CredentialFlow\Support\ExcelSolicitud as X;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;

/**
 * F4: asistente visual de importación inteligente. Pantalla (Inertia), vista previa del certificado desde el borrador y garantías de que ver, revisar y previsualizar
 * no crean nada. La lógica del borrador y la confirmación se prueban en ImportacionInteligenteTest (F3).
 */
class ImportacionAsistenteTest extends EmisionesTestCase
{
    private Plantilla $plantilla;

    private EventoCertificacion $evento;

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
            'database/migrations/2026_10_24_100000_cf_importaciones_table.php',
        ], '--force' => true])->assertExitCode(0);

        config(['credential_flow.importacion_inteligente.activa' => true]);
        $this->plantilla = $this->plantillaCon();
        $this->evento = EventoCertificacion::unguarded(fn () => EventoCertificacion::create([
            'id' => 631, 'nombre' => X::EVENTO_631, 'nombre_normalizado' => NombreConservador::normalizar(X::EVENTO_631), 'anio' => 2026,
            'fecha_texto' => '16 DE OCTUBRE DE 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW,
        ]));
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** Plantilla con textos pequeños (los nombres y el evento son largos) que imprime los cinco campos y, además, los indicados. */
    private function plantillaCon(array $extra = [], array $cambios = []): Plantilla
    {
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];
        $els = [];
        foreach ($campos as $i => $c) {
            $els[] = $this->elemento(['field' => $c, 'y' => 20 + $i * 40, 'height' => 30, 'fontSize' => 12]);
        }
        foreach ($extra as $i => $c) {
            $els[] = $this->elemento(array_merge(['field' => $c, 'y' => 230 + $i * 70, 'height' => 60, 'fontSize' => 12], $cambios));
        }

        return $this->plantillaLista($els);
    }

    private function analizar(?string $bytes = null, $usuario = null): int
    {
        return $this->actingAs($usuario ?? $this->admin())->post(route('credential-flow.importaciones.analizar'), ['archivo' => UploadedFile::fake()->createWithContent('solicitud.xlsx', $bytes ?? X::bytes())], ['Accept' => 'application/json'])
            ->assertSuccessful()->json('borrador.id');
    }

    private function decidir(int $id, array $extra = []): array
    {
        $version = Importacion::findOrFail($id)->version;

        return $this->actingAs($this->admin())->putJson(route('credential-flow.importaciones.correcciones', $id), array_replace([
            'version' => $version, 'plantilla_id' => $this->plantilla->id,
            'evento' => ['decision' => 'asociar', 'evento_id' => 631],
            'confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria', 'modalidad'], 'valores_desactualizados' => true],
        ], $extra))->assertOk()->json('borrador');
    }

    private function vista(int $id, ?int $fila = null, $usuario = null): TestResponse
    {
        return $this->actingAs($usuario ?? $this->admin())->getJson(route('credential-flow.importaciones.vista-previa', $id).($fila ? "?fila=$fila" : ''));
    }

    private function pdfVista(int $id, ?int $fila = null, $usuario = null): TestResponse
    {
        return $this->actingAs($usuario ?? $this->admin())->get(route('credential-flow.importaciones.vista-previa.pdf', $id).($fila ? "?fila=$fila" : ''));
    }

    /** @return array<string,int> */
    private function conteos(): array
    {
        return ['lotes' => Lote::withTrashed()->count(), 'participantes' => Participante::withTrashed()->count(), 'eventos' => EventoCertificacion::withTrashed()->count(), 'emisiones' => Emision::count(), 'movimientos' => Movimiento::count()];
    }

    private function textos(TestResponse $r): array
    {
        return array_column((new InspectorPdf($r->getContent()))->textos(), 'texto');
    }

    // ── Pantalla y bandera ────────────────────────────────────────────────────

    public function test_la_pantalla_solo_existe_con_la_bandera_encendida(): void
    {
        config(['credential_flow.importacion_inteligente.activa' => false]);
        $this->actingAs($this->admin())->get(route('credential-flow.importaciones.asistente'))->assertNotFound();
        $this->actingAs($this->admin())->get(route('credential-flow.lotes.nuevo'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('importacion_inteligente', false));

        config(['credential_flow.importacion_inteligente.activa' => true]);
        $this->actingAs($this->admin())->get(route('credential-flow.lotes.nuevo'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('importacion_inteligente', true));
    }

    public function test_la_pantalla_entrega_lo_necesario_para_empezar_y_ningun_dato_personal(): void
    {
        $propio = $this->analizar();
        $this->analizar(X::bytes(4), $this->superAdmin());   // borrador de otra persona: no debe verse

        $this->actingAs($this->admin())->get(route('credential-flow.importaciones.asistente'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('CredentialFlow/Importacion/Asistente')
            ->has('plantillas', 1)
            ->where('plantillas.0.id', $this->plantilla->id)
            ->has('borradores', 1)
            ->where('borradores.0.id', $propio)
            ->where('borradores.0.participantes', 17)
            ->where('borrador_id', null)
            ->where('limites.retencionDias', 7)
            ->has('qa')
            ->has('qa_automatico'));

        $html = $this->actingAs($this->admin())->get(route('credential-flow.importaciones.asistente'))->getContent();
        foreach (['PERSONA 01', 'persona01@', '1000001'] as $dato) {
            $this->assertStringNotContainsString($dato, $html, 'la pantalla inicial no trae datos del Excel');
        }
    }

    public function test_se_puede_reabrir_un_borrador_por_la_direccion(): void
    {
        $id = $this->analizar();

        $this->actingAs($this->admin())->get(route('credential-flow.importaciones.asistente', ['borrador' => $id]))->assertOk()->assertInertia(fn (Assert $p) => $p->where('borrador_id', $id));
    }

    public function test_las_plantillas_de_pruebas_internas_no_se_ofrecen(): void
    {
        $this->artisan('migrate', ['--path' => ['database/migrations/2026_10_21_100000_cf_contactos_qa_y_plantilla_qa.php'], '--force' => true]);
        $qa = $this->plantillaCon();
        $qa->update(['es_plantilla_qa' => true]);

        $this->actingAs($this->admin())->get(route('credential-flow.importaciones.asistente'))->assertInertia(fn (Assert $p) => $p->has('plantillas', 1)->where('plantillas.0.id', $this->plantilla->id));
    }

    // ── Detalle del borrador para la pantalla ─────────────────────────────────

    public function test_el_borrador_trae_lo_original_las_advertencias_agrupadas_y_el_aviso_de_observaciones(): void
    {
        $id = $this->analizar(X::bytes(17, ['observaciones' => [3 => 'Llega tarde']]));

        $b = $this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.show', $id))->assertOk()->json('borrador');

        $p = collect($b['participantes'])->keyBy('fila')[15];
        $this->assertSame('PERSONA 01 PRUEBA', $p['originales']['nombre_completo']);
        $this->assertSame('persona01@example.test', $p['originales']['correo']);
        $this->assertNull($p['originales']['tipo_documento']);
        $g = $b['avisos_generales'];
        $this->assertSame($b['resumen']['valores_desactualizables'], $g['desactualizables_evento'] + $g['desactualizables_participantes'], 'el total agrupado coincide con el resumen');
        $this->assertGreaterThan(0, $g['desactualizables_evento']);
        $this->assertGreaterThan(0, $g['desactualizables_participantes']);
        $this->assertTrue($g['observaciones_no_se_conservan']);

        $sin = $this->actingAs($this->superAdmin())->getJson(route('credential-flow.importaciones.show', $this->analizar(X::bytes(5), $this->superAdmin())))->json('borrador');
        $this->assertFalse($sin['avisos_generales']['observaciones_no_se_conservan']);
    }

    public function test_una_correccion_conserva_el_valor_original_para_comparar(): void
    {
        $id = $this->analizar();

        $b = $this->decidir($id, ['participantes' => ['15' => ['nombre_completo' => 'NOMBRE CORREGIDO', 'documento' => '0012345']]]);

        $p = collect($b['participantes'])->keyBy('fila')[15];
        $this->assertSame(['NOMBRE CORREGIDO', '0012345'], [$p['nombre_completo'], $p['documento']]);
        $this->assertSame(['PERSONA 01 PRUEBA', '1000001'], [$p['originales']['nombre_completo'], $p['originales']['documento']]);
        $this->assertEqualsCanonicalizing(['nombre_completo', 'documento'], $p['corregidos']);
    }

    // ── Vista previa ──────────────────────────────────────────────────────────

    public function test_sin_plantilla_la_vista_previa_dice_que_falta_y_no_simula_un_pdf(): void
    {
        $id = $this->analizar();
        $antes = $this->conteos();

        $v = $this->vista($id)->assertOk()->json('vista_previa');

        $this->assertFalse($v['pdf_disponible']);
        $this->assertNull($v['plantilla']);
        $this->assertContains('Elige la plantilla del certificado para ver cómo se imprimirá.', $v['problemas']);
        $this->assertTrue($v['es_vista_previa']);
        $campos = collect($v['campos'])->keyBy('clave');
        $this->assertSame('PERSONA 01 PRUEBA', $campos['nombre_completo']['valor']);
        $this->assertSame('1000001', $campos['documento']['valor'], 'sin tipo confirmado no se supone C.C.');
        $this->assertSame(X::MODALIDAD, $campos['modalidad']['valor'], 'modalidad literal');
        $this->assertSame('16 DE OCTUBRE DE 2026', $campos['fecha']['valor']);
        $this->assertNull($campos['ciudad']['valor']);
        $this->assertNull($campos['nombre_completo']['se_imprime'], 'sin plantilla no se sabe qué imprime');
        $this->assertStringContainsString('tipo de documento', implode(' ', $v['avisos']));
        $this->pdfVista($id)->assertStatus(409)->assertJsonPath('error.code', 'VISTA_PREVIA_SIN_PLANTILLA');
        $this->assertSame($antes, $this->conteos());
    }

    public function test_con_plantilla_y_datos_completos_se_dibuja_el_pdf_sin_emitir_nada(): void
    {
        Mail::fake();
        $id = $this->analizar();
        $this->decidir($id);
        $antes = $this->conteos();
        $version = Importacion::findOrFail($id)->version;

        $v = $this->vista($id)->assertOk()->json('vista_previa');
        $this->assertTrue($v['pdf_disponible']);
        $this->assertSame($this->plantilla->id, $v['plantilla']['id']);
        $campos = collect($v['campos'])->keyBy('clave');
        $this->assertTrue($campos['nombre_completo']['se_imprime']);
        $this->assertFalse($campos['modalidad']['se_imprime'], 'esta plantilla no imprime la modalidad');
        $this->assertSame(mb_strtoupper(X::EVENTO_631), mb_strtoupper($campos['evento']['valor']), 'el evento sale del evento elegido');

        $r = $this->pdfVista($id, 20)->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertTrue((new InspectorPdf($r->getContent()))->firmaValida());
        $textos = $this->textos($r);
        $this->assertContains('PERSONA 06 PRUEBA', $textos);
        $this->assertContains('1000006', $textos, 'sin tipo de documento: solo el número');
        $this->assertContains('16 DE OCTUBRE DE 2026', $textos);
        $this->assertContains('5 HORAS', $textos);

        $this->assertSame($antes, $this->conteos(), 'ni emisiones, ni bases, ni participantes, ni eventos, ni movimientos');
        $this->assertSame($version, Importacion::findOrFail($id)->version, 'ver la vista previa no cambia el borrador');
        $this->assertSame([], array_values(array_filter(\Storage::disk('local')->allFiles(), fn ($f) => str_contains($f, 'emision'))));
        Mail::assertNothingSent();
    }

    public function test_la_vista_previa_usa_el_tipo_de_documento_confirmado_y_la_modalidad_literal(): void
    {
        $conModalidad = $this->plantillaCon(['modalidad']);
        $id = $this->analizar();
        $this->decidir($id, ['plantilla_id' => $conModalidad->id, 'participantes' => ['15' => ['tipo_documento' => 'cc']], 'datos_comunes' => ['modalidad' => 'presencial EN SALA — Bogotá']]);

        $textos = $this->textos($this->pdfVista($id, 15)->assertOk());

        $this->assertContains('C.C. 1000001', $textos);
        $this->assertContains('presencial EN SALA — Bogotá', $textos, 'la modalidad no se cambia ni se pasa a mayúsculas');
        $this->assertNotContains(X::MODALIDAD, $textos);
    }

    public function test_nombres_largos_y_textos_de_varias_lineas_se_pueden_revisar(): void
    {
        $largo = 'MARÍA DEL ROSARIO DE LOS ÁNGELES FERNÁNDEZ DE LA TORRE Y VILLALOBOS';
        $plantilla = $this->plantillaCon(['texto_certificacion'], ['multiline' => true]);
        $id = $this->analizar(X::bytes(5, ['nombres' => [2 => $largo]]));
        $this->decidir($id, ['plantilla_id' => $plantilla->id, 'datos_comunes' => ['texto_certificacion' => "Primera línea aprobada.\nSegunda línea aprobada."]]);

        $v = $this->vista($id)->assertOk()->json('vista_previa');
        $this->assertSame(16, $v['fila_nombre_mas_largo'], 'el nombre más largo se puede probar con un clic');

        $textos = $this->textos($this->pdfVista($id, $v['fila_nombre_mas_largo'])->assertOk());
        $this->assertContains($largo, $textos);
        $this->assertContains('Primera línea aprobada.', $textos);
        $this->assertContains('Segunda línea aprobada.', $textos);
        $campo = collect($v['campos'])->firstWhere('clave', 'texto_certificacion');
        $this->assertTrue($campo['varias_lineas']);
        $this->assertSame("Primera línea aprobada.\nSegunda línea aprobada.", $campo['valor']);
    }

    public function test_si_la_plantilla_imprime_un_dato_que_falta_se_explica_exactamente_que_falta(): void
    {
        $conCiudad = $this->plantillaCon(['ciudad']);
        $id = $this->analizar();
        $this->decidir($id, ['plantilla_id' => $conCiudad->id]);

        $v = $this->vista($id)->assertOk()->json('vista_previa');
        $this->assertFalse($v['pdf_disponible']);
        $this->assertSame(['La plantilla imprime «Ciudad» y ese dato todavía no está completo.'], $v['problemas']);
        $this->assertTrue(collect($v['campos'])->firstWhere('clave', 'ciudad')['falta']);

        $r = $this->pdfVista($id)->assertStatus(422);
        $this->assertSame('VISTA_PREVIA_DATOS_INCOMPLETOS', $r->json('error.code'));
        $this->assertStringContainsString('«Ciudad»', $r->json('error.message'));

        // Al escribir la ciudad se puede dibujar.
        $this->decidir($id, ['plantilla_id' => $conCiudad->id, 'datos_comunes' => ['ciudad' => 'Bogotá D.C.']]);
        $this->assertTrue($this->vista($id)->json('vista_previa.pdf_disponible'));
        $this->assertContains('Bogotá D.C.', $this->textos($this->pdfVista($id)->assertOk()));
    }

    public function test_un_texto_que_no_cabe_devuelve_un_error_claro_y_no_un_pdf(): void
    {
        $plantilla = $this->plantillaCon(['texto_certificacion']);   // una sola línea
        $id = $this->analizar();
        $this->decidir($id, ['plantilla_id' => $plantilla->id, 'datos_comunes' => ['texto_certificacion' => str_repeat('Texto muy largo de certificación. ', 12)]]);

        $r = $this->pdfVista($id)->assertStatus(422);

        $this->assertSame('NO_CABE', $r->json('error.code'));
        $this->assertStringContainsString('activa «varias líneas»', $r->json('error.message'));
    }

    public function test_un_participante_excluido_no_aparece_en_la_vista_previa(): void
    {
        $id = $this->analizar(X::bytes(4));
        $this->decidir($id, ['participantes' => ['15' => ['excluir' => true]]]);

        $v = $this->vista($id)->assertOk()->json('vista_previa');

        $this->assertSame(16, $v['participante']['fila'], 'si no se pide una fila, se usa la primera que se importará');
        $this->assertNotContains(15, array_column($v['participantes'], 'fila'));
    }

    public function test_la_vista_previa_es_privada_y_depende_de_la_bandera_y_del_estado(): void
    {
        $id = $this->analizar();
        $this->decidir($id);
        $otro = $this->superAdmin();

        $this->vista($id, null, $otro)->assertNotFound();
        $this->pdfVista($id, null, $otro)->assertNotFound();

        Importacion::query()->whereKey($id)->update(['expira_at' => now()->subMinute()]);
        $this->vista($id)->assertStatus(409);
        $this->pdfVista($id)->assertStatus(409);

        config(['credential_flow.importacion_inteligente.activa' => false]);
        $this->vista($id)->assertNotFound();
        $this->pdfVista($id)->assertNotFound();
    }

    public function test_las_rutas_nuevas_estan_protegidas_como_las_demas(): void
    {
        foreach (['asistente', 'vista-previa', 'vista-previa.pdf'] as $nombre) {
            $mw = app('router')->getRoutes()->getByName('credential-flow.importaciones.'.$nombre)->gatherMiddleware();
            $this->assertContains('web', $mw, $nombre);
            $this->assertContains('auth', $mw, $nombre);
            $this->assertContains('rol:super-admin,admin', $mw, $nombre);
            $this->assertTrue(collect($mw)->contains(fn ($m) => is_string($m) && str_contains($m, 'ImportacionInteligenteActiva')), "$nombre: bandera");
        }
        \Auth::logout();
        $this->get(route('credential-flow.importaciones.asistente'))->assertRedirect();
        $this->actingAs($this->comercial())->get(route('credential-flow.importaciones.asistente'))->assertForbidden();
    }

    // ── Flujo completo de punta a punta (API) ─────────────────────────────────

    public function test_flujo_de_cuatro_pasos_crea_la_base_una_sola_vez_sin_emitir(): void
    {
        Mail::fake();
        $id = $this->analizar();                      // 1. subir
        $b = $this->decidir($id);                     // 2. revisar
        $this->assertTrue($b['validacion']['listo']);
        $this->vista($id)->assertOk();                // 3. vista previa
        $this->pdfVista($id)->assertOk();
        $antes = $this->conteos();
        $this->assertSame(0, $antes['lotes']);

        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => $b['version'], 'confirmar' => true])->assertCreated();   // 4. confirmar
        $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => $b['version'], 'confirmar' => true])->assertStatus(409);

        $this->assertSame(1, Lote::count());
        $this->assertSame(0, Emision::count());
        $this->assertSame(1, EventoCertificacion::count(), 'el evento 631 no se duplicó');
        $this->assertSame(631, Lote::findOrFail($r->json('lote_id'))->evento_id);
        Mail::assertNothingSent();

        $detalle = $this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.show', $id))->assertOk()->json('borrador');
        $this->assertSame(['confirmado', $r->json('lote_id')], [$detalle['estado'], $detalle['lote_id']], 'al reabrirlo, la pantalla puede mostrar el enlace a la base');
        $this->assertSame([], $detalle['participantes'], 'ya no quedan datos personales en el borrador');
    }

    // ── Seguridad del código de la pantalla ───────────────────────────────────

    public function test_el_codigo_del_asistente_no_inserta_html_ni_evita_confirmaciones_del_navegador(): void
    {
        $archivos = array_merge(
            File::allFiles(resource_path('js/Components/CredentialFlow/Importacion')),
            File::allFiles(resource_path('js/Pages/CredentialFlow/Importacion')),
            [new \SplFileInfo(resource_path('js/Composables/CredentialFlow/importacionInteligente.js'))],
        );
        $this->assertGreaterThanOrEqual(10, count($archivos));
        foreach ($archivos as $f) {
            $codigo = file_get_contents($f->getPathname());
            foreach (['v-html', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'new Function', 'window.confirm =', 'confirm = () =>'] as $peligroso) {
                $this->assertStringNotContainsString($peligroso, $codigo, basename($f->getPathname()).': '.$peligroso);
            }
            $this->assertSame(0, preg_match('/(?<![\w.])eval\s*\(/', $codigo), basename($f->getPathname()).': eval()');
        }
    }

    public function test_el_boton_final_dice_crear_base_y_no_emitir(): void
    {
        $codigo = file_get_contents(resource_path('js/Components/CredentialFlow/Importacion/PasoConfirmar.vue'));

        $this->assertStringContainsString('Crear base de participantes', $codigo);
        $this->assertStringNotContainsString('>Emitir', $codigo);
        $this->assertStringNotContainsString('"Emitir', $codigo);
        $this->assertStringContainsString('no se emitirá ningún certificado', $codigo);
    }

    // ── Ronda de ajustes UX: qué impide CREAR y qué solo impide EMITIR ─────────

    public function test_se_puede_crear_la_base_con_tipo_y_elegibilidad_pendientes_si_lo_obligatorio_esta_resuelto(): void
    {
        $id = $this->analizar();
        $b = $this->decidir($id);

        // Lo que impide CREAR la base: nada. Lo que solo impide EMITIR: tipo de documento y elegibilidad de las 17 personas.
        $e = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->assertOk()->json();
        $this->assertTrue($e['listo']);
        $this->assertSame([], $e['bloqueos']);
        $this->assertSame(['tipo_documento' => 17, 'elegibilidad' => 17, 'no_elegibles' => 0, 'asistencia_cupo' => 1], $e['pendientes_para_emitir']);

        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => $b['version'], 'confirmar' => true])->assertCreated();

        $lote = Lote::findOrFail($r->json('lote_id'));
        $reales = $lote->participantes()->where('es_control_qa', false)->get();
        $this->assertCount(17, $reales);
        $this->assertSame(0, $reales->where('tipo_documento_confirmado', true)->count(), 'ninguno tiene el tipo confirmado');
        $this->assertSame(17, $reales->where('elegibilidad', Participante::PENDIENTE)->count(), 'todos siguen pendientes');
        $this->assertSame(0, Emision::count(), 'y por eso no hay nada que emitir');
    }

    public function test_los_pendientes_que_impiden_crear_no_se_confunden_con_los_que_impiden_emitir(): void
    {
        $id = $this->analizar();   // sin plantilla ni decisión del evento ni confirmaciones

        $e = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->assertOk()->json();

        $codigos = array_column($e['bloqueos'], 'codigo');
        $this->assertContains('plantilla_faltante', $codigos);
        $this->assertContains('evento_sin_decision', $codigos);
        $this->assertNotContains('tipo_documento_por_confirmar', $codigos, 'el tipo de documento por confirmar NUNCA bloquea la creación');
        $this->assertNotContains('elegibilidad_pendiente', $codigos);
        $this->assertSame(17, $e['pendientes_para_emitir']['tipo_documento']);
        $this->assertSame(17, $e['pendientes_para_emitir']['elegibilidad']);
    }

    public function test_el_boton_final_no_puede_saltarse_las_validaciones_ni_los_qa(): void
    {
        $id = $this->analizar();
        $b = $this->decidir($id, ['evento' => null]);   // sin decidir el evento
        $antes = $this->conteos();

        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => $b['version'], 'confirmar' => true])->assertStatus(422);
        $this->assertSame('BORRADOR_NO_ESTA_LISTO', $r->json('error.code'));
        $this->assertContains('evento_sin_decision', array_column($r->json('error.detalles'), 'codigo'));
        $this->assertSame($antes, $this->conteos());

        // Sin la aceptación explícita tampoco.
        $b = $this->decidir($id);
        $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => $b['version']])->assertStatus(422)->assertJsonValidationErrors('confirmar');

        // Con el catálogo de contactos QA incompleto (se exige por configuración) tampoco: los ocho QA son obligatorios.
        config(['credential_flow.qa.exigir_catalogo' => true]);
        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => $b['version'], 'confirmar' => true])->assertStatus(422);
        $this->assertContains('catalogo_qa_incompleto', array_column($r->json('error.detalles'), 'codigo'));
        $this->assertSame($antes, $this->conteos(), 'nunca se creó nada');
    }

    public function test_cada_aviso_apunta_al_lugar_exacto_donde_se_resuelve(): void
    {
        $id = $this->analizar(X::bytes(4, ['documentos' => [1 => '7700001', 2 => '7700001'], 'correos' => [3 => 'correo-sin-arroba']]));

        $b = collect($this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->json('bloqueos'));

        // Los datos del certificado dicen QUÉ dato es; las filas con error dicen QUÉ fila y QUÉ columna.
        $this->assertEqualsCanonicalizing(['fecha', 'intensidad_horaria', 'modalidad'], $b->where('codigo', 'dato_comun_sin_confirmar')->pluck('campo')->all());
        $filas = $b->where('codigo', 'fila_invalida')->map(fn ($x) => [$x['fila'], $x['campo']])->values()->all();
        $this->assertContains([16, 'documento'], $filas);
        $this->assertContains([17, 'correo'], $filas);
    }

    public function test_la_pantalla_tiene_los_puntos_de_anclaje_que_usa_ir_a_resolverlo(): void
    {
        $leer = fn (string $f) => file_get_contents(resource_path('js/'.$f));
        $todo = $leer('Components/CredentialFlow/Importacion/TarjetaEvento.vue').$leer('Components/CredentialFlow/Importacion/TarjetaDatos.vue')
            .$leer('Components/CredentialFlow/Importacion/TablaParticipantes.vue').$leer('Components/CredentialFlow/Importacion/PasoVistaPrevia.vue');

        foreach (['data-seccion="evento"', ':data-dato="d.campo"', 'data-aviso-importrange', 'data-confirmar-ambiguos', 'data-seccion="participantes"', 'data-seccion="datos"', 'id="plantilla"',
            'data-lista-participantes', ':data-fila="p.fila"', 'data-campo-fila="documento"', 'data-campo-fila="tipo_documento"', 'data-campo-fila="elegibilidad"', 'campo="correo"', 'campo="nombre_completo"'] as $ancla) {
            $this->assertStringContainsString($ancla, $todo, "falta el anclaje $ancla");
        }
        $composable = $leer('Composables/CredentialFlow/importacionInteligente.js');
        foreach (['[data-seccion=evento]', '[data-seccion=datos]', '[data-aviso-importrange]', '[data-confirmar-ambiguos]', '[data-seccion=participantes]', '#plantilla', '[data-dato="${b.campo}"]'] as $selector) {
            $this->assertStringContainsString($selector, $composable, "el composable no busca $selector");
        }
    }

    public function test_cada_archivo_cuenta_sus_propios_valores_que_pueden_estar_desactualizados(): void
    {
        // En el Excel sintético TODAS las celdas vienen de fórmulas de otra hoja (5 del evento + 5 por persona); en el real solo algunas. El conteo sale de cada archivo.
        foreach ([17 => 90, 6 => 35, 2 => 15] as $personas => $esperado) {
            $id = $this->analizar(X::bytes($personas));
            $b = $this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.show', $id))->assertOk()->json('borrador');

            $this->assertSame($esperado, $b['resumen']['valores_desactualizables'], "$personas personas");
            $g = $b['avisos_generales'];
            $this->assertSame([5, $esperado - 5], [$g['desactualizables_evento'], $g['desactualizables_participantes']]);
            $this->assertSame($esperado, $g['desactualizables_evento'] + $g['desactualizables_participantes'], 'el aviso de la pantalla suma lo mismo que el resumen');
        }

        // Y el aviso se mantiene hasta que se confirma explícitamente.
        $id = $this->analizar();
        $b = $this->decidir($id, ['confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria', 'modalidad'], 'valores_desactualizados' => false]]);
        $this->assertContains('valores_desactualizados_sin_revisar', array_column($b['validacion']['bloqueos'], 'codigo'));
    }

    public function test_el_resumen_de_avisos_cuenta_a_las_personas_con_documento_numerico(): void
    {
        $id = $this->analizar();

        $b = $this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.show', $id))->json('borrador');

        $conAviso = collect($b['participantes'])->filter(fn ($p) => in_array('documento_llego_como_numero', $p['avisos'], true))->count();
        $this->assertSame(17, $conAviso, 'el aviso existe por persona (para el detalle) y la pantalla lo resume una sola vez');
    }

    public function test_los_mensajes_de_error_hablan_de_filas_del_excel_y_no_de_lineas_del_csv_interno(): void
    {
        $id = $this->analizar(X::bytes(4, ['documentos' => [1 => '7700001', 2 => '7700001']]));
        $this->decidir($id);

        $b = collect($this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->json('bloqueos'))->firstWhere('codigo', 'fila_invalida');

        $this->assertSame(16, $b['fila']);
        $this->assertStringContainsString('ya aparece en la fila 15', $b['mensaje'], 'la fila repetida se nombra como en el Excel');
    }

    public function test_la_fila_de_los_errores_es_la_del_excel_aunque_haya_personas_excluidas_antes(): void
    {
        // Persona n = fila 14+n del Excel. Se excluye la persona 5 (fila 19) y se dañan la 101 (fila 115) y la 110 (fila 124): el número visible debe seguir siendo el del Excel.
        $id = $this->analizar(X::bytes(120, ['documentos' => [101 => '1000003'], 'correos' => [110 => 'correo-sin-arroba'], 'nombres' => [101 => 'PERSONA CIENTO UNO']]));
        $this->decidir($id, ['participantes' => ['19' => ['excluir' => true]]]);

        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->assertOk()->json();
        $errores = collect($r['bloqueos'])->where('codigo', 'fila_invalida')->values();

        $doc = $errores->firstWhere('campo', 'documento');
        $this->assertSame(115, $doc['fila'], 'la fila del bloqueo es la del Excel (14 + 101), no la posición en el archivo interno');
        $this->assertStringContainsString('ya aparece en la fila 17', $doc['mensaje'], 'la fila repetida (persona 3) también se nombra como en el Excel');
        $this->assertStringNotContainsString('fila 101', $doc['mensaje'], 'el número de persona o de línea interna no se muestra como fila');
        $this->assertStringNotContainsString('fila 102', $doc['mensaje']);

        $correo = $errores->firstWhere('campo', 'correo');
        $this->assertSame(124, $correo['fila']);

        // La fila del bloqueo identifica a la misma persona que la tabla de la pantalla: es la que recibe el foco.
        $tabla = collect($this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.show', $id))->json('borrador.participantes'))->keyBy('fila');
        $this->assertSame('PERSONA CIENTO UNO', $tabla[115]['nombre_completo']);
        $this->assertSame('1000003', $tabla[115]['documento']);
        $this->assertSame('correo-sin-arroba', $tabla[124]['correo']);
        $this->assertTrue($tabla[19]['excluir'], 'la persona excluida sigue en la tabla con su fila original');
    }
}
