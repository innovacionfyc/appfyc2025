<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/** Las cuatro acciones de la Fase 10B-2B-1 por HTTP: permisos, CSRF, validación, mensajes, botones y privacidad. */
class ConciliacionAdministrativaHttpTest extends VariantesTestCase
{
    private const RUTAS = [
        'consolidar-variantes' => 90, 'consolidar-nombre' => 102, 'requiere-soporte' => 104, 'descartar' => 107,
    ];

    private function enviar(string $ruta, int $old, array $datos = [], $usuario = null): TestResponse
    {
        $extra = $ruta === 'consolidar-nombre' ? ['canonico_id' => $this->idCert(103)] : [];

        return $this->actingAs($usuario ?? $this->admin())->post(
            route('credential-flow.historico.casos.'.$ruta, $this->casoDe($old)),
            $datos + $extra + ['motivo' => self::MOTIVO, 'confirmo' => true]
        );
    }

    private function props(int $old): array
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.show', $this->casoDe($old)))->assertOk()->viewData('page')['props']['caso'];
    }

    public function test_sin_sesion_login_y_rol_no_permitido_403_sin_cambios(): void
    {
        $antes = $this->evidencia().$this->estados();
        foreach (self::RUTAS as $ruta => $old) {
            $this->post(route('credential-flow.historico.casos.'.$ruta, $this->casoDe($old)), ['motivo' => self::MOTIVO, 'confirmo' => true])->assertRedirect(route('login'));
        }
        foreach (self::RUTAS as $ruta => $old) {
            $this->enviar($ruta, $old, [], $this->comercial())->assertForbidden();
        }
        $this->assertSame($antes, $this->evidencia().$this->estados());
        $this->assertSame(0, DB::table('cf_conciliaciones')->where('estado', '!=', 'abierto')->count());
    }

    public function test_las_rutas_son_post_con_sesion_rol_y_csrf(): void
    {
        foreach (array_keys(self::RUTAS) as $nombre) {
            $ruta = Route::getRoutes()->getByName('credential-flow.historico.casos.'.$nombre);
            $this->assertSame(['POST'], $ruta->methods(), $nombre);
            $this->assertContains('auth', $ruta->gatherMiddleware());
            $this->assertContains('rol:super-admin,admin', $ruta->gatherMiddleware());
            $this->assertTrue(collect(app('router')->resolveMiddleware($ruta->gatherMiddleware(), $ruta->excludedMiddleware()))->contains(fn ($m) => is_string($m) && str_contains($m, 'ValidateCsrfToken')), $nombre);
        }
    }

    public function test_el_csrf_se_exige_de_verdad_fuera_de_las_pruebas(): void
    {
        $caso = $this->casoDe(107);
        $this->app['env'] = 'production';

        $this->actingAs($this->admin())->post(route('credential-flow.historico.casos.descartar', $caso), ['motivo' => self::MOTIVO, 'confirmo' => true])->assertStatus(419);
        $this->assertSame('abierto', $this->estadoCaso($caso));
        $this->withSession(['_token' => 't'])->actingAs($this->admin())->post(route('credential-flow.historico.casos.descartar', $caso), ['_token' => 't', 'motivo' => self::MOTIVO, 'confirmo' => true])->assertRedirect();
        $this->assertSame('descartado', $this->estadoCaso($caso));
    }

    public function test_motivo_confirmacion_y_canonico_son_obligatorios(): void
    {
        foreach (self::RUTAS as $ruta => $old) {
            foreach ([['motivo' => ''], ['motivo' => 'corto'], ['motivo' => str_repeat('x', 501)], ['confirmo' => false]] as $malo) {
                $this->enviar($ruta, $old, $malo)->assertSessionHasErrors(array_key_first($malo));
            }
        }
        foreach ([null, 0, 'x'] as $malo) {
            $this->actingAs($this->admin())->post(route('credential-flow.historico.casos.consolidar-nombre', $this->casoDe(102)), ['motivo' => self::MOTIVO, 'confirmo' => true, 'canonico_id' => $malo])->assertSessionHasErrors('canonico_id');
        }
        $this->assertSame(0, DB::table('cf_conciliaciones')->where('estado', '!=', 'abierto')->count());
    }

    public function test_super_admin_y_admin_pueden_y_el_flash_es_de_exito_o_error_claro(): void
    {
        $this->enviar('consolidar-variantes', 90, [], $this->superAdmin())->assertRedirect(route('credential-flow.historico.casos.show', $this->casoDe(90)))->assertSessionHas('success');
        $this->enviar('consolidar-nombre', 102)->assertSessionHas('success');
        $this->enviar('requiere-soporte', 104)->assertSessionHas('success');
        $this->enviar('descartar', 107)->assertSessionHas('success');
        $this->assertEqualsCanonicalizing(['resuelto', 'resuelto', 'requiere_soporte', 'descartado'], collect([90, 102, 104, 107])->map(fn ($o) => $this->estadoCaso($this->casoDe($o)))->all());

        // Repetir y acciones cruzadas.
        $this->enviar('descartar', 107)->assertSessionHas('error', 'Este caso ya fue resuelto.');
        $this->enviar('consolidar-variantes', 90)->assertSessionHas('error', 'Este caso ya fue resuelto.');
        $this->enviar('consolidar-variantes', 104)->assertSessionHas('error');
        $this->enviar('descartar', 106)->assertSessionHas('error');
    }

    public function test_el_detalle_ofrece_la_accion_correcta_y_los_nombres_solo_en_la_variacion_cosmetica(): void
    {
        $esperado = [90 => 'consolidar_variantes', 92 => 'consolidar_variantes', 94 => 'consolidar_variantes', 102 => 'consolidar_nombre', 104 => 'emitir_reemplazo', 106 => 'requiere_soporte', 107 => 'descartar', 108 => 'requiere_soporte', 109 => 'emitir_reemplazo', 110 => 'emitir_reemplazo'];
        foreach ($esperado as $old => $clave) {
            $p = $this->props($old);
            $this->assertSame($clave, $p['accion']['clave'] ?? null, (string) $old);
        }
        $correo = $this->props(94);
        $this->assertSame('Consolidar variantes', $correo['accion']['etiqueta']);
        $this->assertSame(4, count($correo['evidencia']['variantes']));
        $this->assertEmpty($correo['accion']['opciones'] ?? null);
        $this->assertSame($this->idCert(94), $correo['evidencia']['consolidacion']['canonico_id']);

        $cosm = $this->props(102);
        $this->assertSame('Consolidar variación de nombre', $cosm['accion']['etiqueta']);
        $this->assertCount(2, $cosm['accion']['opciones']);
        $this->assertSame([$this->idCert(102), $this->idCert(103)], collect($cosm['accion']['opciones'])->pluck('id')->sort()->values()->all());
        $this->assertEmpty($this->props(104)['accion']['opciones'] ?? null);
    }

    public function test_el_detalle_tras_decidir_muestra_la_decision_y_no_ofrece_acciones(): void
    {
        $this->enviar('requiere-soporte', 104);
        $this->enviar('consolidar-variantes', 90);

        $s = $this->props(104);
        $this->assertSame('requiere_soporte', $s['estado']);
        $this->assertNull($s['accion']);
        $c = $this->props(90);
        $this->assertSame(['resuelto', 'correo_consolidado'], [$c['estado'], $c['resolucion']]);
        $this->assertNull($c['accion']);
    }

    public function test_el_detalle_y_los_listados_no_exponen_correos_ni_documentos_en_claro(): void
    {
        $json = json_encode([$this->props(90), $this->props(94), $this->props(107)]);
        foreach (['a1@example.test', 'a2@example.test', 'c1@example.test', '"8200001"', '"8200003"', 'PAR CORREO A', 'PAR CUATRO'] as $privado) {
            $this->assertStringNotContainsString($privado, $json);
        }
        $lista = $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.index'))->assertOk()->getContent();
        foreach (['@example.test', '8200001', 'PAR CORREO A', 'ANA LUZ UNO'] as $privado) {
            $this->assertStringNotContainsString($privado, $lista);
        }
    }

    public function test_el_portal_y_la_verificacion_no_cambian_con_las_acciones_de_soporte_y_descarte(): void
    {
        $antes = [$this->get('/verificar/6201')->getContent() === '', DB::table('cf_migraciones_map')->count()];
        $this->enviar('requiere-soporte', 104);
        $this->enviar('descartar', 107);
        $this->assertSame($antes, [$this->get('/verificar/6201')->getContent() === '', DB::table('cf_migraciones_map')->count()]);
        $this->assertSame('pendiente_conciliacion', $this->cert(104)->conciliacion_estado);
    }
}
