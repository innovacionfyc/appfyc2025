<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/** Acción «Consolidar diferencia de código» por HTTP (Fase 10B-2A): permisos, CSRF, validación, mensajes, botón y privacidad. */
class ConsolidacionCodigoHttpTest extends ConsolidacionTestCase
{
    private function enviar(int $caso, array $datos = [], $usuario = null): TestResponse
    {
        return $this->actingAs($usuario ?? $this->admin())->post(route('credential-flow.historico.casos.consolidar-codigo', $caso), $datos + ['motivo' => self::MOTIVO, 'confirmo' => true]);
    }

    private function props(int $caso): array
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.show', $caso))->assertOk()->viewData('page')['props']['caso'];
    }

    public function test_sin_sesion_va_al_login_y_un_rol_no_permitido_recibe_403_sin_cambiar_nada(): void
    {
        $caso = $this->caso(70);
        $antes = $this->evidencia().$this->firmaCaso($caso);

        $this->post(route('credential-flow.historico.casos.consolidar-codigo', $caso), ['motivo' => self::MOTIVO, 'confirmo' => true])->assertRedirect(route('login'));
        $this->enviar($caso, [], $this->comercial())->assertForbidden();

        $this->assertSame($antes, $this->evidencia().$this->firmaCaso($caso));
    }

    public function test_super_admin_y_admin_pueden_consolidar(): void
    {
        $this->enviar($this->caso(70), [], $this->superAdmin())->assertRedirect();
        $this->enviar($this->caso(72), [], $this->admin())->assertRedirect();

        $this->assertEqualsCanonicalizing([$this->superAdmin()->id, $this->admin()->id], DB::table('cf_conciliaciones')->where('estado', 'resuelto')->pluck('resuelto_por')->map(fn ($i) => (int) $i)->all());
    }

    public function test_la_ruta_es_post_con_sesion_rol_y_csrf(): void
    {
        $ruta = Route::getRoutes()->getByName('credential-flow.historico.casos.consolidar-codigo');

        $this->assertSame(['POST'], $ruta->methods());
        $this->assertContains('auth', $ruta->gatherMiddleware());
        $this->assertContains('rol:super-admin,admin', $ruta->gatherMiddleware());
        $this->assertTrue(collect(app('router')->resolveMiddleware($ruta->gatherMiddleware(), $ruta->excludedMiddleware()))->contains(fn ($m) => is_string($m) && str_contains($m, 'ValidateCsrfToken')));
        $this->assertContains($this->actingAs($this->admin())->get('/admin/credential-flow/historico/casos/1/consolidar-codigo')->getStatusCode(), [404, 405]);
    }

    public function test_el_csrf_se_exige_de_verdad_fuera_de_las_pruebas(): void
    {
        $caso = $this->caso(70);
        $this->app['env'] = 'production';

        $this->actingAs($this->admin())->post(route('credential-flow.historico.casos.consolidar-codigo', $caso), ['motivo' => self::MOTIVO, 'confirmo' => true])->assertStatus(419);
        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($caso)->estado);

        $this->withSession(['_token' => 't'])->actingAs($this->admin())->post(route('credential-flow.historico.casos.consolidar-codigo', $caso), ['_token' => 't', 'motivo' => self::MOTIVO, 'confirmo' => true])->assertRedirect();
        $this->assertSame('resuelto', DB::table('cf_conciliaciones')->find($caso)->estado);
    }

    public function test_el_motivo_y_la_confirmacion_son_obligatorios(): void
    {
        $caso = $this->caso(70);
        foreach ([['motivo' => ''], ['motivo' => 'corto'], ['motivo' => str_repeat('x', 501)], ['confirmo' => false], ['confirmo' => null]] as $malo) {
            $this->actingAs($this->admin())->post(route('credential-flow.historico.casos.consolidar-codigo', $caso), $malo + ['motivo' => self::MOTIVO, 'confirmo' => true])->assertSessionHasErrors(array_key_first($malo));
        }
        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($caso)->estado);
    }

    public function test_consolidar_por_http_resuelve_y_el_detalle_muestra_el_resultado(): void
    {
        $caso = $this->caso(70);

        $this->enviar($caso)->assertRedirect(route('credential-flow.historico.casos.show', $caso))->assertSessionHas('success');

        $p = $this->props($caso);
        $this->assertSame('Resuelto', $p['estado_info']['etiqueta']);
        $this->assertNull($p['accion']);
        $this->assertSame('consolidada', $p['evidencia']['consolidacion']['estado']);
        $this->assertSame($this->idCert(70), $p['evidencia']['consolidacion']['canonico_id']);
        $this->assertSame(['canonico', 'consolidada'], collect($p['evidencia']['variantes'])->pluck('rol')->sort()->values()->all());
        $this->assertSame(['detectado', 'conflicto_codigo_consolidado'], array_column($p['bitacora'], 'accion'));
        $this->assertSame(self::MOTIVO, $p['bitacora'][1]['motivo']);
        $this->assertSame('codigo_consolidado', $p['resolucion']);
    }

    public function test_repetir_por_http_dice_que_ya_fue_resuelto_y_no_duplica(): void
    {
        $caso = $this->caso(70);
        $this->enviar($caso)->assertSessionHas('success');
        $antes = $this->firmaCaso($caso);

        $this->enviar($caso)->assertRedirect()->assertSessionHas('error', 'Este caso ya fue resuelto.');

        $this->assertSame($antes, $this->firmaCaso($caso));
    }

    public function test_un_dif_correo_o_dif_nombre_vuelve_con_mensaje_y_no_cambia_nada(): void
    {
        foreach ([74, 9] as $old) {
            $caso = $this->caso($old);
            $antes = $this->evidencia().$this->firmaCaso($caso);

            $this->enviar($caso)->assertRedirect()->assertSessionHas('error', 'Este caso no es una diferencia de solo código: requiere revisión humana.');

            $this->assertSame($antes, $this->evidencia().$this->firmaCaso($caso));
        }
    }

    public function test_el_boton_solo_aparece_para_dif_verif_abiertos_y_aplicables(): void
    {
        $verif = $this->props($this->caso(70));
        $this->assertSame('Consolidar diferencia de código', $verif['accion']['etiqueta']);
        $this->assertTrue($verif['acciones_disponibles']);
        $this->assertFalse($verif['accion']['requiere_archivo']);
        $this->assertSame('propuesta', $verif['evidencia']['consolidacion']['estado']);
        $this->assertSame($this->idCert(70), $verif['evidencia']['consolidacion']['canonico_id']);
        $this->assertSame('6101', $verif['evidencia']['consolidacion']['codigo']);
        $this->assertSame([$this->idCert(70) => 1], $verif['evidencia']['consolidacion']['descargas_historicas']);

        // Esta acción (diferencia de código) NO se ofrece para DIF_CORREO, DIF_NOMBRE ni «código y nombre»: cada uno tiene la suya o ninguna.
        foreach ([74 => 'consolidar_variantes', 9 => 'emitir_reemplazo', 76 => null] as $old => $clave) {
            $p = $this->props($this->caso($old));
            $this->assertNotSame('consolidar_codigo', $p['accion']['clave'] ?? null);
            $this->assertSame($clave, $p['accion']['clave'] ?? null, (string) $old);
            if ($old === 76) {
                $this->assertSame('no_aplica', $p['evidencia']['consolidacion']['estado']);
            }
        }
    }

    public function test_un_dif_verif_con_evidencia_incompatible_muestra_el_bloqueo_en_lugar_de_ofrecer_la_accion(): void
    {
        $p = $this->props($this->caso(78));   // la fila sin código también tiene descargas históricas

        $this->assertTrue($p['evidencia']['consolidacion']['aplicable']);
        $this->assertFalse($p['acciones_disponibles']);
        $this->assertNotEmpty($p['evidencia']['consolidacion']['bloqueos']);
        $this->assertNotNull($p['accion']['bloqueo']);
    }

    public function test_el_detalle_no_expone_datos_personales_antes_ni_despues(): void
    {
        $caso = $this->caso(70);
        $antes = json_encode($this->props($caso));
        $this->enviar($caso);
        $despues = json_encode($this->props($caso));

        foreach ([$antes, $despues] as $json) {
            foreach (['PAR ALFA', 'd8100001@example.test', '"8100001"'] as $privado) {
                $this->assertStringNotContainsString($privado, $json);
            }
        }
    }

    public function test_el_certificado_consolidado_deja_de_avisar_de_diferencias_entre_registros(): void
    {
        $tipos = fn () => collect($this->actingAs($this->admin())->get(route('credential-flow.historico.certificados.show', $this->idCert(71)))->assertOk()->viewData('page')['props']['certificado']['avisos'])->pluck('titulo')->all();

        $this->assertContains('Diferencias entre registros históricos', $tipos());
        $this->enviar($this->caso(70));
        $this->assertNotContains('Diferencias entre registros históricos', $tipos());
        $this->assertContains('Diferencia de código consolidada', $tipos());
    }

    public function test_ver_el_detalle_no_escribe_nada(): void
    {
        $caso = $this->caso(70);
        $sentencias = $this->sentencias(fn () => $this->props($caso));

        foreach ($sentencias as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $sql);
        }
    }
}
