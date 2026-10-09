<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/** Acciones de resolución de plantillas por HTTP (Fase 10B-1): permisos, CSRF, validación, mensajes, idempotencia y botones del detalle. */
class ConciliacionesAccionesTest extends ConciliacionesTestCase
{
    private const MOTIVO = 'Decisión administrativa de prueba.';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->detectar();
    }

    private function id(string $tipo, ?int $oldCert = null): int
    {
        return $oldCert === null ? (int) $this->caso($tipo)->id : (int) DB::table('cf_conciliaciones_certificados')->join('cf_conciliaciones as c', 'c.id', '=', 'conciliacion_id')->where('certificado_legado_id', $this->idCert($oldCert))->where('c.tipo', $tipo)->value('c.id');
    }

    private function enviar(string $ruta, int $caso, array $datos = [], $usuario = null): TestResponse
    {
        return $this->actingAs($usuario ?? $this->admin())->post(route("credential-flow.historico.casos.{$ruta}", $caso), $datos + ['motivo' => self::MOTIVO, 'confirmo' => true]);
    }

    private function props(int $caso): array
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.show', $caso))->assertOk()->viewData('page')['props'];
    }

    private function resumenBD(): string
    {
        return md5(json_encode([DB::table('cf_conciliaciones')->orderBy('id')->get()->all(), DB::table('cf_conciliaciones_eventos')->orderBy('id')->get()->all(), DB::table('cf_certificados_legado')->orderBy('id')->get()->all(), DB::table('cf_plantillas_legado')->orderBy('id')->get()->all()]));
    }

    // ── Permisos y CSRF ──────────────────────────────────────────────────────────────────────────────────

    public function test_sin_sesion_va_al_login_y_un_rol_no_permitido_recibe_403_sin_cambiar_nada(): void
    {
        $caso = $this->id(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        $antes = $this->resumenBD();

        $this->post(route('credential-flow.historico.casos.aprobar-candidata', $caso), ['motivo' => self::MOTIVO, 'confirmo' => true])->assertRedirect(route('login'));
        $this->enviar('aprobar-candidata', $caso, [], $this->comercial())->assertForbidden();

        $this->assertSame($antes, $this->resumenBD());
    }

    public function test_super_admin_y_admin_pueden_resolver(): void
    {
        $this->enviar('aprobar-candidata', $this->id(Conciliacion::TIPO_PLANTILLA_CANDIDATA), [], $this->superAdmin())->assertRedirect();
        $this->enviar('confirmar-renderizable', $this->id(Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO), [], $this->admin())->assertRedirect();

        $this->assertSame(2, DB::table('cf_conciliaciones')->where('estado', 'resuelto')->count());
        $this->assertEqualsCanonicalizing([$this->superAdmin()->id, $this->admin()->id], DB::table('cf_conciliaciones')->where('estado', 'resuelto')->pluck('resuelto_por')->map(fn ($i) => (int) $i)->all());
    }

    public function test_las_tres_rutas_son_post_con_middleware_de_sesion_rol_y_csrf(): void
    {
        foreach (['aprobar-candidata', 'confirmar-renderizable', 'aportar-plantilla'] as $r) {
            $ruta = Route::getRoutes()->getByName("credential-flow.historico.casos.{$r}");
            $this->assertSame(['POST'], $ruta->methods());
            $this->assertContains('auth', $ruta->gatherMiddleware());
            $this->assertContains('rol:super-admin,admin', $ruta->gatherMiddleware());
            $resueltos = app('router')->resolveMiddleware($ruta->gatherMiddleware(), $ruta->excludedMiddleware());
            $this->assertTrue(collect($resueltos)->contains(fn ($m) => is_string($m) && str_contains($m, 'ValidateCsrfToken')), "{$r} sin CSRF");
            // Un GET a una acción no existe.
            $this->assertContains($this->actingAs($this->admin())->get('/admin/credential-flow/historico/casos/1/'.$r)->getStatusCode(), [404, 405]);
        }
    }

    public function test_el_csrf_se_exige_de_verdad_fuera_de_las_pruebas(): void
    {
        $caso = $this->id(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        $this->app['env'] = 'production';   // el middleware de CSRF se desactiva solo en el entorno de pruebas

        $this->actingAs($this->admin())->post(route('credential-flow.historico.casos.aprobar-candidata', $caso), ['motivo' => self::MOTIVO, 'confirmo' => true])->assertStatus(419);
        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($caso)->estado);

        $this->withSession(['_token' => 'token-de-prueba'])->actingAs($this->admin())->post(route('credential-flow.historico.casos.aprobar-candidata', $caso), ['_token' => 'token-de-prueba', 'motivo' => self::MOTIVO, 'confirmo' => true])->assertRedirect();
        $this->assertSame('resuelto', DB::table('cf_conciliaciones')->find($caso)->estado);
    }

    // ── Validación ───────────────────────────────────────────────────────────────────────────────────────

    public function test_el_motivo_y_la_confirmacion_son_obligatorios(): void
    {
        $caso = $this->id(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        $antes = $this->resumenBD();
        foreach ([['motivo' => ''], ['motivo' => 'corto'], ['motivo' => str_repeat('x', 501)], ['confirmo' => false], ['confirmo' => null]] as $malo) {
            $campo = array_key_first($malo);
            $r = $this->actingAs($this->admin())->post(route('credential-flow.historico.casos.aprobar-candidata', $caso), $malo + ['motivo' => self::MOTIVO, 'confirmo' => true]);
            $r->assertSessionHasErrors($campo);
        }
        $this->assertSame($antes, $this->resumenBD());
    }

    public function test_un_caso_inexistente_da_404_y_un_tipo_equivocado_vuelve_con_mensaje(): void
    {
        $this->actingAs($this->admin())->post('/admin/credential-flow/historico/casos/99999/aprobar-candidata', ['motivo' => self::MOTIVO, 'confirmo' => true])->assertNotFound();

        $conflicto = $this->id(Conciliacion::TIPO_CONFLICTO_VARIANTES);
        $this->enviar('aprobar-candidata', $conflicto)->assertRedirect(route('credential-flow.historico.casos.show', $conflicto))->assertSessionHas('error', 'Esta acción no corresponde a este tipo de caso.');
        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($conflicto)->estado);
    }

    // ── Resolver y repetir ───────────────────────────────────────────────────────────────────────────────

    public function test_aprobar_candidata_por_http_resuelve_y_muestra_el_resultado(): void
    {
        $caso = $this->id(Conciliacion::TIPO_PLANTILLA_CANDIDATA);

        $this->enviar('aprobar-candidata', $caso)->assertRedirect(route('credential-flow.historico.casos.show', $caso))->assertSessionHas('success');

        $props = $this->props($caso)['caso'];
        $this->assertSame('Resuelto', $props['estado_info']['etiqueta']);
        $this->assertNull($props['accion']);
        $this->assertFalse($props['acciones_disponibles']);
        $this->assertSame(['detectado', 'plantilla_candidata_aprobada'], array_column($props['bitacora'], 'accion'));
        $this->assertSame('Administración', $props['bitacora'][1]['actor']);
        $this->assertSame(self::MOTIVO, $props['bitacora'][1]['motivo']);
    }

    public function test_repetir_la_accion_por_http_dice_que_ya_fue_resuelto_y_no_duplica(): void
    {
        $caso = $this->id(Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO);
        $this->enviar('confirmar-renderizable', $caso)->assertSessionHas('success');
        $antes = $this->resumenBD();

        $this->enviar('confirmar-renderizable', $caso)->assertRedirect()->assertSessionHas('error', 'Este caso ya fue resuelto.');

        $this->assertSame($antes, $this->resumenBD());
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->where('accion', 'plantilla_renderizable_confirmada')->count());
    }

    public function test_un_pdf_congelado_vuelve_con_el_mensaje_humano(): void
    {
        $caso = $this->id(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(30))->update(['pdf_archivo' => 'x.pdf']);

        $this->enviar('aprobar-candidata', $caso)->assertSessionHas('error', 'Este certificado ya tiene un archivo histórico generado y no puede cambiarse de plantilla directamente.');

        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($caso)->estado);
        $p = $this->props($caso)['caso'];
        $this->assertFalse($p['acciones_disponibles']);
        $this->assertStringContainsString('ya tiene un archivo histórico generado', $p['accion']['bloqueo']);
    }

    // ── Subida de la plantilla ───────────────────────────────────────────────────────────────────────────

    public function test_aportar_plantilla_por_http_con_un_png_valido(): void
    {
        $caso = $this->id(Conciliacion::TIPO_PLANTILLA_FALTANTE, 32);

        $this->enviar('aportar-plantilla', $caso, ['archivo' => UploadedFile::fake()->image('cualquier-nombre.png', 400, 300)])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('resuelto', DB::table('cf_conciliaciones')->find($caso)->estado);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertStringNotContainsString('cualquier-nombre', json_encode([DB::table('cf_plantillas_legado')->get(), DB::table('cf_conciliaciones_eventos')->get(), DB::table('cf_plantillas_legado_contenidos')->get()]));
    }

    public function test_aportar_plantilla_exige_archivo_y_rechaza_pdf_o_texto_con_error_en_el_campo(): void
    {
        $caso = $this->id(Conciliacion::TIPO_PLANTILLA_FALTANTE, 32);
        $antes = $this->resumenBD();

        $this->actingAs($this->admin())->post(route('credential-flow.historico.casos.aportar-plantilla', $caso), ['motivo' => self::MOTIVO, 'confirmo' => true])->assertSessionHasErrors('archivo');
        $this->enviar('aportar-plantilla', $caso, ['archivo' => UploadedFile::fake()->create('plantilla.pdf', 50, 'application/pdf')])->assertSessionHasErrors('archivo');
        $this->enviar('aportar-plantilla', $caso, ['archivo' => UploadedFile::fake()->createWithContent('falsa.png', 'no soy una imagen')])->assertSessionHasErrors('archivo');

        $this->assertSame($antes, $this->resumenBD());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ── Botones del detalle ──────────────────────────────────────────────────────────────────────────────

    public function test_cada_caso_de_plantilla_ofrece_su_boton_y_los_demas_no(): void
    {
        $esperado = [
            Conciliacion::TIPO_PLANTILLA_CANDIDATA => 'Aprobar y asociar', Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO => 'Confirmar como renderizable', Conciliacion::TIPO_PLANTILLA_FALTANTE => 'Aportar plantilla',
            Conciliacion::TIPO_IDENTIDAD_AMBIGUA => null,
        ];
        foreach ($esperado as $tipo => $etiqueta) {
            $p = $this->props($this->id($tipo))['caso'];
            $this->assertSame($etiqueta, $p['accion']['etiqueta'] ?? null, $tipo);
            $this->assertSame($etiqueta !== null, $p['acciones_disponibles'], $tipo);
        }
        $this->assertTrue($this->props($this->id(Conciliacion::TIPO_PLANTILLA_FALTANTE))['caso']['accion']['requiere_archivo']);
        $this->assertSame(['formatos' => ['JPEG', 'PNG'], 'max_mb' => 10], $this->props($this->id(Conciliacion::TIPO_PLANTILLA_FALTANTE))['caso']['accion']['archivo']);
    }

    public function test_la_bandeja_no_tiene_acciones(): void
    {
        $props = $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.index'))->assertOk()->viewData('page')['props'];

        foreach ($props['casos']['data'] as $fila) {
            $this->assertArrayNotHasKey('accion', $fila);
        }
        $this->assertStringNotContainsString('aprobar', json_encode($props['casos']));
    }

    public function test_las_acciones_no_exponen_datos_personales_en_ningun_detalle_resuelto(): void
    {
        $this->enviar('aprobar-candidata', $this->id(Conciliacion::TIPO_PLANTILLA_CANDIDATA));
        $this->enviar('confirmar-renderizable', $this->id(Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO));
        $this->enviar('aportar-plantilla', $this->id(Conciliacion::TIPO_PLANTILLA_FALTANTE, 32), ['archivo' => UploadedFile::fake()->image('a.png', 400, 300)]);

        foreach (DB::table('cf_conciliaciones')->pluck('id') as $id) {
            $json = json_encode($this->props((int) $id));
            foreach ([self::NOMBRE_P3, self::CORREO_P3, self::DOC_P3, 'MARIO TREINTA', 'NORA TREINTA', 'mario31@example.test', 'nora32@example.test'] as $privado) {
                $this->assertStringNotContainsString($privado, $json);
            }
        }
    }
}
