<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Importacion\ServicioImportaciones;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\CredentialFlow\Support\ExcelSolicitud as X;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;
use Tests\Feature\CredentialFlow\Support\XlsxSintetico;

/**
 * F3 de la importación inteligente: borradores revisables, API del asistente y confirmación transaccional. Todo con Excel SINTÉTICOS; el evento «631» es un
 * evento de prueba con el mismo nombre del real.
 */
class ImportacionInteligenteTest extends EmisionesTestCase
{
    private const CONTACTOS = [['QA UNO PRUEBA', 'C.C. 8.000.001', 'qa.uno@example.test'], ['QA DOS PRUEBA', 'C.C. 8.000.002', 'qa.dos@example.test'], ['QA TRES PRUEBA', 'C.C. 8.000.003', 'qa.tres@example.test']];

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

        config(['credential_flow.importacion_inteligente.activa' => true, 'credential_flow.qa.contactos_esperados' => 3]);
        $this->plantilla = $this->plantillaLista();
        $this->evento = $this->crearEvento631();
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    private function crearEvento631(): EventoCertificacion
    {
        return EventoCertificacion::unguarded(fn () => EventoCertificacion::create([
            'id' => 631, 'nombre' => X::EVENTO_631, 'nombre_normalizado' => NombreConservador::normalizar(X::EVENTO_631), 'anio' => 2026,
            'fecha_texto' => '16 DE OCTUBRE DE 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW,
        ]));
    }

    private function sembrarQa(int $cuantos = 3): void
    {
        foreach (array_slice(self::CONTACTOS, 0, $cuantos) as [$n, $d, $c]) {
            \DB::table('cf_contactos_qa')->insert(['nombre_completo' => $n, 'documento' => $d, 'documento_clave' => Texto::claveDocumento($d), 'correo' => $c, 'correo_normalizado' => $c, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function archivo(?string $bytes = null, string $nombre = 'solicitud.xlsx'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, $bytes ?? X::bytes());
    }

    private function analizar(?string $bytes = null, $usuario = null): TestResponse
    {
        return $this->actingAs($usuario ?? $this->admin())->post(route('credential-flow.importaciones.analizar'), ['archivo' => $this->archivo($bytes)], ['Accept' => 'application/json']);
    }

    /** @param  array<string,mixed>  $cambios */
    private function corregir(int $id, int $version, array $cambios, $usuario = null): TestResponse
    {
        return $this->actingAs($usuario ?? $this->admin())->putJson(route('credential-flow.importaciones.correcciones', $id), ['version' => $version] + $cambios);
    }

    private function validarBorrador(int $id, $usuario = null): TestResponse
    {
        return $this->actingAs($usuario ?? $this->admin())->postJson(route('credential-flow.importaciones.validar', $id));
    }

    private function confirmar(int $id, int $version, $usuario = null, bool $explicito = true): TestResponse
    {
        return $this->actingAs($usuario ?? $this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => $version] + ($explicito ? ['confirmar' => true] : []));
    }

    /** Todo lo que el administrador debe decidir en el caso real: plantilla, evento, y haber revisado lo que puede estar desactualizado. */
    private function decisiones(array $extra = []): array
    {
        return array_replace([
            'plantilla_id' => $this->plantilla->id,
            'evento' => ['decision' => 'asociar', 'evento_id' => $this->evento->id],
            'confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria', 'modalidad'], 'valores_desactualizados' => true],
        ], $extra);
    }

    /** Crea el borrador y deja las decisiones tomadas. @return array{0:int,1:int} id y versión */
    private function borradorListo(?string $bytes = null, array $extra = []): array
    {
        $id = $this->analizar($bytes)->assertSuccessful()->json('borrador.id');
        $v = $this->corregir($id, 1, $this->decisiones($extra))->assertOk()->json('borrador.version');

        return [$id, $v];
    }

    /** @return array<string,int> */
    private function conteos(): array
    {
        return [
            'lotes' => Lote::withTrashed()->count(), 'participantes' => Participante::withTrashed()->count(), 'eventos' => EventoCertificacion::withTrashed()->count(),
            'emisiones' => Emision::count(), 'correos' => (int) \DB::table('cf_correos')->count(),
        ];
    }

    // ── Interruptor y rutas ───────────────────────────────────────────────────

    public function test_con_el_interruptor_apagado_todas_las_rutas_nuevas_responden_404_y_lo_antiguo_sigue(): void
    {
        config(['credential_flow.importacion_inteligente.activa' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(route('credential-flow.importaciones.index'))->assertNotFound();
        $this->actingAs($admin)->post(route('credential-flow.importaciones.analizar'), ['archivo' => $this->archivo()], ['Accept' => 'application/json'])->assertNotFound();
        $this->actingAs($admin)->getJson(route('credential-flow.importaciones.show', 1))->assertNotFound();
        $this->actingAs($admin)->putJson(route('credential-flow.importaciones.correcciones', 1), ['version' => 1])->assertNotFound();
        $this->actingAs($admin)->postJson(route('credential-flow.importaciones.validar', 1))->assertNotFound();
        $this->actingAs($admin)->postJson(route('credential-flow.importaciones.confirmar', 1), ['version' => 1, 'confirmar' => true])->assertNotFound();
        $this->actingAs($admin)->postJson(route('credential-flow.importaciones.rechazar', 1))->assertNotFound();
        $this->assertSame(0, Importacion::count());

        // El procedimiento de siempre no cambia.
        $this->actingAs($admin)->get(route('credential-flow.lotes.nuevo'))->assertOk();
        $this->assertFalse(config('credential_flow.importacion_inteligente.activa'));
    }

    public function test_el_interruptor_viene_apagado_por_defecto_en_la_configuracion(): void
    {
        $this->assertFalse((require base_path('config/credential_flow.php'))['importacion_inteligente']['activa']);
    }

    public function test_las_rutas_usan_autenticacion_rol_csrf_y_limites(): void
    {
        foreach (['analizar', 'correcciones', 'validar', 'confirmar', 'rechazar', 'show', 'index'] as $nombre) {
            $ruta = app('router')->getRoutes()->getByName('credential-flow.importaciones.'.$nombre);
            $mw = $ruta->gatherMiddleware();
            $this->assertContains('web', $mw, "$nombre: grupo web (CSRF)");
            $this->assertContains('auth', $mw, $nombre);
            $this->assertContains('rol:super-admin,admin', $mw, $nombre);
            $this->assertTrue(collect($mw)->contains(fn ($m) => is_string($m) && str_contains($m, 'ImportacionInteligenteActiva')), "$nombre: interruptor");
            if ($nombre !== 'show' && $nombre !== 'index') {
                $this->assertTrue(collect($mw)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), "$nombre: límite propio");
            }
        }
    }

    public function test_sin_sesion_o_sin_rol_no_se_puede_usar(): void
    {
        \Auth::logout();   // la preparación del caso dejó una sesión iniciada
        $this->postJson(route('credential-flow.importaciones.analizar'), ['archivo' => $this->archivo()])->assertUnauthorized();
        $this->actingAs($this->comercial())->postJson(route('credential-flow.importaciones.analizar'), ['archivo' => $this->archivo()])->assertForbidden();
        $this->assertSame(0, Importacion::count());
    }

    // ── Borradores ────────────────────────────────────────────────────────────

    public function test_analizar_crea_un_borrador_y_no_crea_nada_mas(): void
    {
        $antes = $this->conteos();

        $r = $this->analizar()->assertCreated();

        $this->assertSame($antes, $this->conteos(), 'ni bases, ni participantes, ni eventos, ni emisiones, ni correos');
        $this->assertFalse($r->json('recuperado'));
        $b = $r->json('borrador');
        $this->assertSame(['en_revision', 1, 17, 14], [$b['estado'], $b['version'], count($b['participantes']), $b['fila_encabezado']]);
        $this->assertNotNull($b['expira_at']);
        $this->assertSame($this->admin()->id, Importacion::findOrFail($b['id'])->usuario_id);
    }

    public function test_mismo_administrador_y_mismo_archivo_recuperan_el_mismo_borrador(): void
    {
        $primero = $this->analizar()->assertCreated()->json('borrador.id');
        $r = $this->analizar()->assertOk();

        $this->assertTrue($r->json('recuperado'));
        $this->assertSame($primero, $r->json('borrador.id'));
        $this->assertSame(1, Importacion::count());

        // Otro administrador con el mismo archivo tiene su propio borrador.
        $otro = $this->analizar(null, $this->superAdmin())->assertCreated()->json('borrador.id');
        $this->assertNotSame($primero, $otro);

        // Un archivo distinto es otro borrador.
        $this->analizar(X::bytes(5))->assertCreated();
        $this->assertSame(3, Importacion::count());
    }

    public function test_los_datos_personales_del_borrador_estan_cifrados_y_el_resumen_no_los_tiene(): void
    {
        $id = $this->analizar()->assertCreated()->json('borrador.id');

        $crudo = (array) \DB::table('cf_importaciones')->where('id', $id)->first();
        foreach (['PERSONA 01 PRUEBA', 'persona01@example.test', '1000001'] as $dato) {
            $this->assertStringNotContainsString($dato, $crudo['analisis'], 'el análisis viaja cifrado');
            $this->assertStringNotContainsString($dato, $crudo['correcciones']);
            $this->assertStringNotContainsString($dato, (string) $crudo['resumen']);
            $this->assertStringNotContainsString($dato, (string) $crudo['advertencias']);
            $this->assertStringNotContainsString($dato, (string) $crudo['archivo_nombre']);
        }
        $this->assertStringNotContainsString('PERSONA', json_encode(Importacion::findOrFail($id)->toArray()), 'el modelo oculta los datos personales');
        $this->assertSame([], array_values(array_filter(Storage::disk('local')->allFiles(), fn ($f) => ! str_starts_with($f, 'credential-flow/plantillas/'))), 'el archivo original no se conserva');
    }

    public function test_un_borrador_es_privado_de_quien_lo_creo(): void
    {
        $id = $this->analizar()->assertCreated()->json('borrador.id');
        $otro = $this->superAdmin();

        $this->actingAs($otro)->getJson(route('credential-flow.importaciones.show', $id))->assertNotFound();
        $this->corregir($id, 1, ['plantilla_id' => $this->plantilla->id], $otro)->assertNotFound();
        $this->validarBorrador($id, $otro)->assertNotFound();
        $this->confirmar($id, 1, $otro)->assertNotFound();
        $this->actingAs($otro)->postJson(route('credential-flow.importaciones.rechazar', $id))->assertNotFound();
        $this->assertSame([], $this->actingAs($otro)->getJson(route('credential-flow.importaciones.index'))->json('borradores'));
        $this->assertSame(1, $this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.index'))->assertOk()->json('borradores.0.version'));
        $this->assertSame('en_revision', Importacion::findOrFail($id)->estado);
    }

    public function test_un_archivo_que_no_es_un_excel_valido_se_rechaza_sin_crear_borrador(): void
    {
        $this->analizar('esto no es un zip')->assertStatus(422)->assertJsonPath('error.code', 'ARCHIVO_INVALIDO');
        $this->analizar(XlsxSintetico::libro([['nombre' => 'Hoja', 'celdas' => ['A1' => 'solo un texto']]]))
            ->assertStatus(422)->assertJsonPath('error.code', 'TABLA_DE_PARTICIPANTES_NO_ENCONTRADA');
        $this->assertSame(0, Importacion::count());
    }

    // ── Correcciones y versiones ──────────────────────────────────────────────

    public function test_corregir_incrementa_la_version_y_una_version_vieja_se_rechaza(): void
    {
        $id = $this->analizar()->assertCreated()->json('borrador.id');

        $r = $this->corregir($id, 1, ['nombre_base' => 'Base de prueba'])->assertOk();
        $this->assertSame([2, 'Base de prueba'], [$r->json('borrador.version'), $r->json('borrador.nombre_base')]);

        $this->corregir($id, 1, ['nombre_base' => 'Otra'])->assertStatus(409)->assertJsonPath('error.code', 'BORRADOR_DESACTUALIZADO');
        $this->assertSame('Base de prueba', $this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.show', $id))->json('borrador.nombre_base'));

        $this->actingAs($this->admin())->putJson(route('credential-flow.importaciones.correcciones', $id), ['nombre_base' => 'x'])->assertStatus(422);
    }

    public function test_las_correcciones_invalidas_se_rechazan_completas_sin_guardar_nada(): void
    {
        $id = $this->analizar()->assertCreated()->json('borrador.id');

        $casos = [
            ['inventado' => 1],
            ['participantes' => ['15' => ['elegibilidad' => 'si']]],
            ['participantes' => ['15' => ['tipo_documento' => 'Licencia']]],
            ['participantes' => ['99' => ['excluir' => true]]],
            ['participantes' => ['15' => ['nombre_completo' => 'OK'], '16' => ['correo_x' => 'a']]],
            ['datos_comunes' => ['color' => 'azul']],
            ['plantilla_id' => 999999],
            ['evento' => ['decision' => 'borrar']],
            ['masivas' => [['filas' => 'todas', 'campos' => ['nombre_completo' => 'X']]]],
        ];
        foreach ($casos as $caso) {
            $this->corregir($id, 1, $caso)->assertStatus(422)->assertJsonPath('error.code', 'CORRECCION_INVALIDA');
        }
        $this->assertSame(1, Importacion::findOrFail($id)->version, 'nada se guardó ni se subió la versión');
    }

    public function test_correcciones_individuales_y_masivas(): void
    {
        $id = $this->analizar()->assertCreated()->json('borrador.id');

        $r = $this->corregir($id, 1, [
            'masivas' => [['filas' => 'todas', 'senal' => 'completo', 'campos' => ['elegibilidad' => 'elegible']], ['filas' => [15, 16], 'campos' => ['tipo_documento' => 'cc']]],
            'participantes' => ['17' => ['nombre_completo' => 'NOMBRE CORREGIDO', 'excluir' => true]],
        ])->assertOk();

        $p = collect($r->json('borrador.participantes'))->keyBy('fila');
        $this->assertSame(['elegible', 'C.C.'], [$p[15]['elegibilidad'], $p[15]['tipo_documento']]);
        $this->assertSame(['elegible', null], [$p[20]['elegibilidad'], $p[20]['tipo_documento']]);
        $this->assertSame('pendiente', $p[30]['elegibilidad'], 'Cupos no se vuelve elegible por una corrección sobre los «Completo»');
        $this->assertTrue($p[17]['excluir']);
        $this->assertSame('NOMBRE CORREGIDO', $p[17]['nombre_completo']);
        $this->assertContains('nombre_completo', $p[17]['corregidos']);

        // Quitar una corrección devuelve el valor detectado.
        $r = $this->corregir($id, 2, ['participantes' => ['17' => null]])->assertOk();
        $this->assertSame('PERSONA 03 PRUEBA', collect($r->json('borrador.participantes'))->keyBy('fila')[17]['nombre_completo']);
    }

    // ── Caso real (sintético): lo detectado y lo pendiente ────────────────────

    public function test_el_borrador_conserva_lo_detectado_sus_origenes_y_sus_advertencias(): void
    {
        $b = $this->analizar()->assertCreated()->json('borrador');

        $this->assertSame(17, $b['resumen']['participantes']);
        $this->assertSame(['completo' => 16, 'cupo' => 1], $b['resumen']['senales']);
        $this->assertSame(0, $b['resumen']['posibles_qa']);
        $this->assertGreaterThan(0, $b['resumen']['valores_desactualizables']);

        $modalidad = $b['datos_comunes']['modalidad'];
        $this->assertSame(X::MODALIDAD, $modalidad['valor']);
        $this->assertSame(X::MODALIDAD, $modalidad['propuesta']);
        $this->assertTrue($modalidad['desactualizable']);
        $this->assertFalse($modalidad['confirmado']);
        $this->assertTrue($modalidad['requiere_confirmacion']);
        $this->assertSame('G8', $modalidad['referencia']);
        $this->assertSame('16 DE OCTUBRE DE 2026', $b['datos_comunes']['fecha']['valor']);
        $this->assertSame('5 HORAS', $b['datos_comunes']['intensidad_horaria']['valor']);
        $this->assertNull($b['datos_comunes']['ciudad']['valor'], 'la ciudad no se inventa');

        $p = collect($b['participantes'])->keyBy('fila');
        $this->assertSame(15, $p->keys()->first());
        $this->assertSame(31, $p->keys()->last());
        foreach ($p as $fila) {
            $this->assertNull($fila['tipo_documento'], 'ningún tipo de documento se supone');
            $this->assertSame('pendiente', $fila['elegibilidad'], 'ni Completo ni Cupos presumen elegibilidad');
            $this->assertFalse($fila['excluir']);
            $this->assertContains('tipo_documento_por_confirmar', $fila['avisos']);
            $this->assertContains('valores_posiblemente_desactualizados', $fila['avisos']);
        }
        $cupo = $p[30];
        $this->assertSame(['cupo', 'Cupos'], [$cupo['senal'], $cupo['sesion']]);
        $this->assertContains('asistencia_cupo', $cupo['avisos']);
        $this->assertSame(false, $p[15]['diploma_marcado']);       // informativo

        // Todavía no está listo y dice por qué.
        $codigos = array_column($b['validacion']['bloqueos'], 'codigo');
        $this->assertFalse($b['validacion']['listo']);
        foreach (['plantilla_faltante', 'evento_sin_decision', 'dato_comun_sin_confirmar', 'valores_desactualizados_sin_revisar'] as $esperado) {
            $this->assertContains($esperado, $codigos);
        }
    }

    // ── Evento existente ──────────────────────────────────────────────────────

    public function test_se_propone_asociar_al_evento_existente_y_muestra_la_diferencia_sin_crear_nada(): void
    {
        $antes = $this->conteos();
        $nombreAntes = $this->evento->fresh()->getAttributes();

        $prop = $this->analizar()->assertCreated()->json('borrador.evento.propuesta');

        $this->assertSame('asociar_existente', $prop['accion']);
        $this->assertSame(631, $prop['evento_propuesto_id']);
        $this->assertTrue($prop['requiere_confirmacion']);
        $this->assertStringContainsString('Jineth Bedoya Lima', $prop['nombre_excel']);
        $c = $prop['candidatos'][0];
        $this->assertSame([631, X::EVENTO_631, 'contenido'], [$c['id'], $c['nombre'], $c['coincidencia']]);
        $this->assertSame('(Ley Jineth Bedoya Lima)', $c['diferencia']['adicional_en_excel']);
        $this->assertNull($c['diferencia']['faltante_en_excel']);
        $this->assertFalse($c['diferencia']['iguales']);
        $this->assertStringContainsString('NO crear otro', implode(' ', $prop['advertencias']));

        $this->assertSame($antes, $this->conteos(), 'no se creó ningún evento ni nada más');
        $this->assertSame($nombreAntes, $this->evento->fresh()->getAttributes(), 'el evento existente no se modifica (ni siquiera su fecha de actualización)');
    }

    public function test_sin_decision_del_evento_no_se_puede_confirmar_y_no_se_crea_ni_modifica_ningun_evento(): void
    {
        $id = $this->analizar()->assertCreated()->json('borrador.id');
        $v = $this->corregir($id, 1, $this->decisiones(['evento' => null]) + ['evento' => null])->assertOk()->json('borrador.version');
        $antes = $this->conteos();

        $r = $this->confirmar($id, $v)->assertStatus(422);

        $this->assertSame('BORRADOR_NO_ESTA_LISTO', $r->json('error.code'));
        $this->assertContains('evento_sin_decision', array_column($r->json('error.detalles'), 'codigo'));
        $this->assertSame($antes, $this->conteos());
    }

    public function test_crear_un_evento_parecido_exige_confirmar_que_es_distinto_y_uno_exacto_no_se_duplica(): void
    {
        $id = $this->analizar()->assertCreated()->json('borrador.id');
        $v = $this->corregir($id, 1, $this->decisiones(['evento' => ['decision' => 'crear', 'nombre' => 'Seminario completamente distinto de pruebas 2026']]))->assertOk()->json('borrador.version');
        // El nombre propuesto por el Excel es parecido al #631; uno claramente distinto no lo es.
        $this->assertNotContains('evento_similar_sin_confirmar', array_column($this->validarBorrador($id)->json('bloqueos'), 'codigo'));

        $v = $this->corregir($id, $v, ['evento' => ['decision' => 'crear', 'nombre' => null]])->assertOk()->json('borrador.version');   // vuelve al nombre del Excel
        $codigos = array_column($this->validarBorrador($id)->json('bloqueos'), 'codigo');
        $this->assertContains('evento_similar_sin_confirmar', $codigos);

        $v = $this->corregir($id, $v, ['evento' => ['decision' => 'crear', 'nombre' => X::EVENTO_631]])->assertOk()->json('borrador.version');
        $this->assertContains('evento_ya_existe', array_column($this->validarBorrador($id)->json('bloqueos'), 'codigo'));
        $antes = $this->conteos();
        $this->confirmar($id, $v)->assertStatus(422);
        $this->assertSame($antes, $this->conteos());
    }

    public function test_el_administrador_puede_confirmar_un_evento_nuevo_distinto(): void
    {
        [$id, $v] = $this->borradorListo(null, ['evento' => ['decision' => 'crear', 'nombre' => 'Seminario completamente distinto de pruebas 2026', 'confirmar_distinto' => true]]);

        $r = $this->confirmar($id, $v)->assertCreated();

        $lote = Lote::findOrFail($r->json('lote_id'));
        $nuevo = EventoCertificacion::query()->where('nombre', 'Seminario completamente distinto de pruebas 2026')->firstOrFail();
        $this->assertSame($nuevo->id, $lote->evento_id);
        $this->assertSame(2, EventoCertificacion::count());
        $this->assertSame('SEMINARIO COMPLETAMENTE DISTINTO DE PRUEBAS 2026', $lote->datos_comunes['evento']);
        $this->assertSame(X::EVENTO_631, $this->evento->fresh()->nombre);
    }

    // ── Confirmación ──────────────────────────────────────────────────────────

    public function test_confirmar_exige_la_aceptacion_explicita(): void
    {
        [$id, $v] = $this->borradorListo();

        $this->confirmar($id, $v, null, false)->assertStatus(422)->assertJsonValidationErrors('confirmar');
        $this->assertSame(0, Lote::count());
        $this->assertSame('en_revision', Importacion::findOrFail($id)->estado);
    }

    public function test_confirmar_crea_la_base_con_los_17_participantes_asociada_al_evento_631(): void
    {
        $this->sembrarQa();
        $bytes = X::bytes();
        [$id, $v] = $this->borradorListo($bytes);
        $antes = $this->conteos();

        $this->validarBorrador($id)->assertOk()->assertJsonPath('listo', true)->assertJsonPath('resumen.incluidos', 17);
        $this->assertSame($antes, $this->conteos(), 'validar no escribe');
        $r = $this->confirmar($id, $v)->assertCreated();

        $lote = Lote::findOrFail($r->json('lote_id'));
        $this->assertSame(631, $lote->evento_id);
        $this->assertSame(1, Lote::count());
        $this->assertSame(1, EventoCertificacion::count(), 'no se creó otro evento');
        $this->assertSame(X::EVENTO_631, $this->evento->fresh()->nombre, 'el evento existente no se modificó');
        $reales = $lote->participantes()->where('es_control_qa', false)->orderBy('fila_origen')->get();
        $this->assertCount(17, $reales);
        $this->assertSame(['PERSONA 01 PRUEBA', '1000001'], [$reales[0]->nombre_completo, $reales[0]->documento]);
        foreach ($reales as $p) {
            $this->assertFalse((bool) $p->tipo_documento_confirmado, 'los 17 siguen con el tipo de documento por confirmar');
            $this->assertSame(Participante::PENDIENTE, $p->elegibilidad, 'ninguno es elegible sin decisión');
        }
        $this->assertSame('VIRTUAL-SINCRÓNICO Y REMOTO', mb_strtoupper(X::MODALIDAD));
        $this->assertSame(X::MODALIDAD, $lote->datos_comunes['modalidad'], 'modalidad literal');
        $this->assertSame(['16 DE OCTUBRE DE 2026', '5 HORAS'], [$lote->datos_comunes['fecha'], $lote->datos_comunes['intensidad_horaria']]);
        $this->assertArrayNotHasKey('ciudad', $lote->datos_comunes);
        $this->assertSame(hash('sha256', $bytes), $lote->archivo_hash, 'la base recuerda el Excel ORIGINAL');
        $this->assertSame('solicitud.xlsx', $lote->archivo_nombre);

        $imp = Importacion::findOrFail($id);
        $this->assertSame(['confirmado', $lote->id], [$imp->estado, $imp->lote_id]);
        $this->assertNull($imp->getRawOriginal('analisis'), 'los datos personales del borrador se borran al confirmar');
        $this->assertNull($imp->getRawOriginal('correcciones'));
        $this->assertNull($imp->clave_activa);
    }

    public function test_cupos_y_completo_no_se_vuelven_elegibles_solos_y_nada_se_emite(): void
    {
        Mail::fake();
        Queue::fake();
        Notification::fake();
        [$id, $v] = $this->borradorListo(null, ['masivas' => [['filas' => 'todas', 'senal' => 'completo', 'campos' => ['elegibilidad' => 'elegible']]]]);

        $lote = Lote::findOrFail($this->confirmar($id, $v)->assertCreated()->json('lote_id'));

        $porEstado = $lote->participantes()->where('es_control_qa', false)->pluck('elegibilidad', 'fila_origen');
        $this->assertSame(16, $porEstado->filter(fn ($e) => $e === Participante::ELEGIBLE)->count(), 'solo los que el administrador marcó');
        $this->assertSame(Participante::PENDIENTE, $porEstado[17], 'Cupos queda pendiente');   // fila Excel 30 → línea de CSV 17
        // Aunque sean elegibles, sin tipo de documento confirmado no hay nada para emitir.
        try {
            app(EmisorLote::class)->emitirPendientes($lote, null);
            $this->fail('No debe haber nada que emitir.');
        } catch (EmisionException $e) {
            $this->assertSame(EmisionException::SIN_PENDIENTES, $e->codigo);
        }
        $this->assertSame(0, Emision::count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame([], array_values(array_filter(Storage::disk('local')->allFiles(), fn ($f) => str_contains($f, 'emision'))));
        $this->assertSame(0, (int) \DB::table('cf_correos')->where('origen', '!=', 'credential_flow')->count());
    }

    public function test_confirmar_dos_veces_no_duplica_la_base(): void
    {
        [$id, $v] = $this->borradorListo();
        $primero = $this->confirmar($id, $v)->assertCreated()->json('lote_id');

        $segundo = $this->confirmar($id, $v)->assertStatus(409);
        $this->assertSame('BORRADOR_YA_CONFIRMADO', $segundo->json('error.code'));
        $this->confirmar($id, $v + 1)->assertStatus(409);
        $this->corregir($id, $v + 1, ['nombre_base' => 'x'])->assertStatus(409);
        $this->validarBorrador($id)->assertStatus(409);
        $this->assertSame(1, Lote::count());
        $this->assertSame($primero, Importacion::findOrFail($id)->lote_id);
    }

    public function test_un_borrador_desactualizado_no_importa_en_silencio(): void
    {
        [$id, $v] = $this->borradorListo();
        $this->corregir($id, $v, ['nombre_base' => 'Cambio hecho en otra pestaña'])->assertOk();

        $r = $this->confirmar($id, $v)->assertStatus(409);

        $this->assertSame('BORRADOR_DESACTUALIZADO', $r->json('error.code'));
        $this->assertSame(0, Lote::count());
        $this->assertSame('en_revision', Importacion::findOrFail($id)->estado);
    }

    public function test_si_el_borrador_cambia_a_mitad_de_la_confirmacion_se_rechaza(): void
    {
        [$id, $v] = $this->borradorListo();
        // Simula a otra petición que gana la carrera entre la lectura y el bloqueo.
        Importacion::query()->whereKey($id)->update(['version' => $v + 5]);

        $this->confirmar($id, $v)->assertStatus(409)->assertJsonPath('error.code', 'BORRADOR_DESACTUALIZADO');
        $this->assertSame(0, Lote::count());
    }

    public function test_el_borrador_no_confirma_si_hay_pendientes_y_los_lista(): void
    {
        $id = $this->analizar()->assertCreated()->json('borrador.id');

        $r = $this->confirmar($id, 1)->assertStatus(422);

        $this->assertSame('BORRADOR_NO_ESTA_LISTO', $r->json('error.code'));
        $this->assertGreaterThanOrEqual(4, count($r->json('error.detalles')));
        $this->assertSame(0, Lote::count());
        $this->assertSame(0, Participante::count());
    }

    public function test_los_datos_dudosos_del_excel_exigen_confirmacion_antes_de_importar(): void
    {
        $id = $this->analizar()->assertCreated()->json('borrador.id');
        $v = $this->corregir($id, 1, $this->decisiones(['confirmados' => ['datos_comunes' => [], 'valores_desactualizados' => false]]))->assertOk()->json('borrador.version');

        $bloqueos = collect($this->validarBorrador($id)->json('bloqueos'));
        $this->assertEqualsCanonicalizing(['fecha', 'intensidad_horaria', 'modalidad'], $bloqueos->where('codigo', 'dato_comun_sin_confirmar')->pluck('campo')->all());
        $this->assertTrue($bloqueos->contains('codigo', 'valores_desactualizados_sin_revisar'));

        // Corregir un dato lo vuelve del administrador (no necesita otra confirmación).
        $this->corregir($id, $v, ['datos_comunes' => ['modalidad' => 'Presencial'], 'confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria'], 'valores_desactualizados' => true]])->assertOk();
        $this->validarBorrador($id)->assertOk()->assertJsonPath('listo', true);
    }

    public function test_la_modalidad_aprobada_pasa_literal_al_pdf_sin_transcripcion_manual(): void
    {
        // Plantilla que imprime la modalidad (F2).
        $els = array_map(fn ($c, $i) => $this->elemento(['field' => $c, 'y' => 20 + $i * 40, 'height' => 30, 'fontSize' => 12]), ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria', 'modalidad'], range(0, 5));
        $conModalidad = $this->plantillaLista($els);
        $id = $this->analizar()->assertCreated()->json('borrador.id');

        // Sin la modalidad confirmada ni escrita: bloqueada por el diseño NO; sí por la confirmación.
        $v = $this->corregir($id, 1, $this->decisiones(['plantilla_id' => $conModalidad->id, 'confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria'], 'valores_desactualizados' => true]]))->assertOk()->json('borrador.version');
        $this->assertContains('dato_comun_sin_confirmar', array_column($this->validarBorrador($id)->json('bloqueos'), 'codigo'));

        $v = $this->corregir($id, $v, ['confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria', 'modalidad']]])->assertOk()->json('borrador.version');
        $lote = Lote::findOrFail($this->confirmar($id, $v)->assertCreated()->json('lote_id'));
        $this->assertSame(X::MODALIDAD, $lote->datos_comunes['modalidad']);

        $p = $lote->participantes()->first();
        $r = $this->actingAs($this->admin())->get(route('credential-flow.participantes.pdf', [$lote, $p]))->assertOk();
        $textos = array_column((new InspectorPdf($r->getContent()))->textos(), 'texto');
        $this->assertContains(X::MODALIDAD, $textos);
    }

    public function test_si_la_plantilla_imprime_un_dato_que_el_excel_no_trae_se_bloquea(): void
    {
        $els = array_map(fn ($c, $i) => $this->elemento(['field' => $c, 'y' => 20 + $i * 40, 'height' => 30]), ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria', 'ciudad'], range(0, 5));
        $conCiudad = $this->plantillaLista($els);
        $id = $this->analizar()->assertCreated()->json('borrador.id');
        $this->corregir($id, 1, $this->decisiones(['plantilla_id' => $conCiudad->id]))->assertOk();

        $bloqueo = collect($this->validarBorrador($id)->json('bloqueos'))->firstWhere('codigo', 'datos_del_diseno_incompletos');

        $this->assertSame(['Ciudad'], $bloqueo['campos']);
    }

    public function test_una_plantilla_de_pruebas_internas_no_sirve_para_importar(): void
    {
        $this->artisan('migrate', ['--path' => ['database/migrations/2026_10_21_100000_cf_contactos_qa_y_plantilla_qa.php'], '--force' => true]);
        $this->plantilla->update(['es_plantilla_qa' => true]);
        $id = $this->analizar()->assertCreated()->json('borrador.id');
        $this->corregir($id, 1, $this->decisiones())->assertOk();

        $this->assertContains('plantilla_invalida', array_column($this->validarBorrador($id)->json('bloqueos'), 'codigo'));
    }

    // ── Participantes: documentos, tipos, errores del importador ─────────────

    public function test_los_documentos_conservan_sus_ceros_iniciales_y_no_se_recuperan_por_suposicion(): void
    {
        [$id, $v] = $this->borradorListo(X::bytes(3, ['documentos' => [1 => '0012345678'], 'documento_base' => 5000000]));

        $lote = Lote::findOrFail($this->confirmar($id, $v)->assertCreated()->json('lote_id'));

        $docs = $lote->participantes()->where('es_control_qa', false)->orderBy('fila_origen')->pluck('documento')->all();
        $this->assertSame(['0012345678', '5000002', '5000003'], $docs);
    }

    public function test_los_errores_del_importador_se_muestran_con_la_fila_del_excel_y_bloquean(): void
    {
        $id = $this->analizar(X::bytes(4, ['documentos' => [1 => '7700001', 2 => '7700001']]))->assertCreated()->json('borrador.id');
        $this->corregir($id, 1, $this->decisiones())->assertOk();

        $bloqueo = collect($this->validarBorrador($id)->json('bloqueos'))->firstWhere('codigo', 'fila_invalida');

        $this->assertSame([16, 'DOCUMENTO_DUPLICADO'], [$bloqueo['fila'], $bloqueo['error']]);
        $this->assertSame(0, Lote::count());
        // Excluir la repetida lo resuelve.
        $this->corregir($id, 2, ['participantes' => ['16' => ['excluir' => true]]])->assertOk();
        $this->validarBorrador($id)->assertOk()->assertJsonPath('listo', true)->assertJsonPath('resumen.incluidos', 3);
    }

    public function test_el_mismo_excel_no_se_importa_dos_veces_en_el_mismo_evento(): void
    {
        [$id, $v] = $this->borradorListo();
        $this->confirmar($id, $v)->assertCreated();

        // Otro administrador sube el mismo archivo y lo asocia al mismo evento.
        $otro = $this->superAdmin();
        $id2 = $this->analizar(null, $otro)->assertCreated()->json('borrador.id');
        $this->corregir($id2, 1, $this->decisiones(), $otro)->assertOk();

        $this->assertContains('archivo_repetido_en_evento', array_column($this->validarBorrador($id2, $otro)->json('bloqueos'), 'codigo'));
    }

    public function test_el_importador_tradicional_conserva_su_comportamiento(): void
    {
        $this->actingAs($this->admin());
        $csv = UploadedFile::fake()->createWithContent('b.csv', "nombre_completo,documento\nUNO,9100001\nDOS,9100002\n");
        $comunes = ['evento' => 'EVENTO', 'fecha' => '1 de octubre de 2026', 'intensidad_horaria' => '1 hora'];

        $a = app(ImportadorParticipantes::class)->importar($csv, $this->plantilla, 'A', null, $comunes);
        $b = app(ImportadorParticipantes::class)->importar(UploadedFile::fake()->createWithContent('otro.csv', "nombre_completo,documento\nTRES,9100003\n"), $this->plantilla, 'B', null, $comunes, null, str_repeat('a', 64), 'original.xlsx');

        $this->assertSame([hash('sha256', "nombre_completo,documento\nUNO,9100001\nDOS,9100002\n"), 'b.csv'], [$a->archivo_hash, $a->archivo_nombre], 'sin parámetros nuevos: igual que siempre');
        $this->assertSame([str_repeat('a', 64), 'original.xlsx'], [$b->archivo_hash, $b->archivo_nombre]);
    }

    // ── Contactos QA ──────────────────────────────────────────────────────────

    public function test_los_contactos_qa_se_incorporan_a_la_base_nueva(): void
    {
        $this->sembrarQa();
        [$id, $v] = $this->borradorListo();

        $lote = Lote::findOrFail($this->confirmar($id, $v)->assertCreated()->json('lote_id'));

        $this->assertSame([17, 3], [$lote->participantes()->where('es_control_qa', false)->count(), $lote->participantes()->where('es_control_qa', true)->count()]);
        $this->assertSame(0, Emision::count());
    }

    public function test_un_fallo_al_incorporar_los_qa_no_deja_base_ni_participantes_ni_evento(): void
    {
        $this->sembrarQa();
        Participante::creating(function (Participante $p) {
            if ($p->es_control_qa) {
                throw new RuntimeException('fallo simulado con datos como PERSONA 01 PRUEBA');
            }
        });
        [$id, $v] = $this->borradorListo(null, ['evento' => ['decision' => 'crear', 'nombre' => 'Evento que debe deshacerse completo 2026', 'confirmar_distinto' => true]]);
        $antes = $this->conteos();

        $r = $this->confirmar($id, $v)->assertStatus(500);

        $this->assertSame('QA_INCORPORACION_FALLIDA', $r->json('error.code'));
        $this->assertStringNotContainsString('PERSONA', $r->getContent());
        $this->assertSame($antes, $this->conteos(), 'todo o nada: ni base, ni participantes, ni evento nuevo');
        $imp = Importacion::findOrFail($id);
        $this->assertSame('en_revision', $imp->estado);
        $this->assertSame($v, $imp->version);
        $this->assertNotNull($imp->analisis, 'el borrador sigue intacto para reintentar');
    }

    public function test_si_los_qa_quedan_incompletos_aunque_nadie_falle_no_se_crea_la_base(): void
    {
        $this->sembrarQa();
        // Cada contacto QA «se crea» pero queda borrado: el resultado de la incorporación dice éxito y la base no lo tiene.
        Participante::created(function (Participante $p) {
            if ($p->es_control_qa) {
                \DB::table('cf_participantes')->where('id', $p->id)->update(['deleted_at' => now()]);
            }
        });
        [$id, $v] = $this->borradorListo();
        $antes = $this->conteos();

        $this->confirmar($id, $v)->assertStatus(409)->assertJsonPath('error.code', 'QA_INCORPORACION_FALLIDA');

        $this->assertSame($antes, $this->conteos(), 'se cuenta lo realmente guardado, no lo que dice el resultado');
        $this->assertSame('en_revision', Importacion::findOrFail($id)->estado);
    }

    public function test_un_participante_real_con_el_documento_de_un_contacto_qa_bloquea_la_confirmacion(): void
    {
        $this->sembrarQa();
        $id = $this->analizar(X::bytes(3, ['documentos' => [2 => 'C.C. 8.000.002']]))->assertCreated()->json('borrador.id');
        $this->corregir($id, 1, $this->decisiones(['participantes' => ['16' => ['tipo_documento' => 'C.C.', 'documento' => '8.000.002']]]))->assertOk();

        $bloqueo = collect($this->validarBorrador($id)->json('bloqueos'))->firstWhere('codigo', 'documento_de_contacto_qa');

        $this->assertSame(16, $bloqueo['fila']);
        $this->assertSame(0, Lote::count());
    }

    public function test_sin_el_catalogo_qa_completo_no_se_puede_confirmar_cuando_se_exige(): void
    {
        config(['credential_flow.qa.exigir_catalogo' => true]);
        $this->sembrarQa(2);
        [$id] = $this->borradorListo();

        $this->assertContains('catalogo_qa_incompleto', array_column($this->validarBorrador($id)->json('bloqueos'), 'codigo'));
    }

    // ── Caducidad y limpieza ──────────────────────────────────────────────────

    public function test_un_borrador_vencido_se_borra_y_no_se_puede_usar(): void
    {
        $id = $this->analizar()->assertCreated()->json('borrador.id');
        Importacion::query()->whereKey($id)->update(['expira_at' => now()->subMinute()]);

        $this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.show', $id))->assertOk()->assertJsonPath('borrador.estado', 'expirado')->assertJsonPath('borrador.participantes', []);
        $this->corregir($id, 1, ['nombre_base' => 'x'])->assertStatus(410)->assertJsonPath('error.code', 'BORRADOR_EXPIRADO');
        $this->confirmar($id, 1)->assertStatus(410);

        $fila = (array) \DB::table('cf_importaciones')->where('id', $id)->first();
        $this->assertSame(['expirado', null, null, null], [$fila['estado'], $fila['analisis'], $fila['correcciones'], $fila['clave_activa']]);
        $this->assertSame(0, Lote::count());
    }

    public function test_el_vencimiento_se_aplica_aunque_nadie_haya_consultado_el_borrador(): void
    {
        $id = $this->analizar()->assertCreated()->json('borrador.id');
        Importacion::query()->whereKey($id)->update(['expira_at' => now()->subMinute()]);

        $this->artisan('credential-flow:importaciones-limpiar')->assertExitCode(0)->expectsOutputToContain('Borradores vencidos: 1');

        $fila = (array) \DB::table('cf_importaciones')->where('id', $id)->first();
        $this->assertSame(['expirado', null, null], [$fila['estado'], $fila['analisis'], $fila['correcciones']]);
        // Se puede volver a subir el mismo Excel: nace un borrador nuevo.
        $this->analizar()->assertCreated();
    }

    public function test_la_limpieza_elimina_las_filas_viejas_y_conserva_las_recientes(): void
    {
        $viejo = $this->analizar(X::bytes(3))->assertCreated()->json('borrador.id');
        $reciente = $this->analizar(X::bytes(4))->assertCreated()->json('borrador.id');
        $activo = $this->analizar(X::bytes(5))->assertCreated()->json('borrador.id');
        $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.rechazar', $viejo))->assertOk();
        $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.rechazar', $reciente))->assertOk();
        Importacion::query()->whereKey($viejo)->update(['updated_at' => now()->subDays(31)]);

        $this->artisan('credential-flow:importaciones-limpiar')->assertExitCode(0);

        $this->assertNull(Importacion::find($viejo));
        $this->assertSame('rechazado', Importacion::findOrFail($reciente)->estado);
        $this->assertSame('en_revision', Importacion::findOrFail($activo)->estado);
        $this->assertNotNull(Importacion::findOrFail($activo)->analisis, 'un borrador vigente no se toca');
    }

    public function test_rechazar_borra_los_datos_personales(): void
    {
        $id = $this->analizar()->assertCreated()->json('borrador.id');

        $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.rechazar', $id))->assertOk();

        $fila = (array) \DB::table('cf_importaciones')->where('id', $id)->first();
        $this->assertSame(['rechazado', null, null, null], [$fila['estado'], $fila['analisis'], $fila['correcciones'], $fila['clave_activa']]);
        $this->confirmar($id, 2)->assertStatus(409);
        $this->assertSame(0, Lote::count());
    }

    public function test_la_retencion_es_configurable(): void
    {
        config(['credential_flow.importacion_inteligente.retencion_dias' => 2]);
        $id = $this->analizar()->assertCreated()->json('borrador.id');

        $dias = Importacion::findOrFail($id)->expira_at->diffInDays(Importacion::findOrFail($id)->created_at, true);
        $this->assertEqualsWithDelta(2, $dias, 0.01);
        $this->assertSame(7, (require base_path('config/credential_flow.php'))['importacion_inteligente']['retencion_dias']);
    }

    public function test_la_limpieza_esta_programada_a_diario(): void
    {
        $this->artisan('schedule:list')->assertExitCode(0)->expectsOutputToContain('credential-flow:importaciones-limpiar');
    }

    // ── Migración ─────────────────────────────────────────────────────────────

    public function test_la_migracion_es_aditiva_y_reversible(): void
    {
        $this->assertTrue(\Schema::hasTable('cf_importaciones'));
        foreach (['usuario_id', 'estado', 'version', 'clave_activa', 'archivo_hash', 'analisis', 'correcciones', 'lote_id', 'expira_at'] as $columna) {
            $this->assertTrue(\Schema::hasColumn('cf_importaciones', $columna), $columna);
        }
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_10_24_100000_cf_importaciones_table.php', '--force' => true])->assertExitCode(0);
        $this->assertFalse(\Schema::hasTable('cf_importaciones'));
        $this->assertTrue(\Schema::hasTable('cf_lotes'), 'ninguna otra tabla se toca');
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_24_100000_cf_importaciones_table.php', '--force' => true])->assertExitCode(0);
        $this->assertTrue(\Schema::hasTable('cf_importaciones'));
    }

    public function test_el_servicio_no_se_activa_solo(): void
    {
        $this->assertTrue(ServicioImportaciones::activa());
        config(['credential_flow.importacion_inteligente.activa' => false]);
        $this->assertFalse(ServicioImportaciones::activa());
        $this->assertSame(0, Importacion::count());
    }
}
