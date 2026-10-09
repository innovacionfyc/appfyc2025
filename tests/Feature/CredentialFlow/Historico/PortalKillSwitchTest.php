<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Support\CredentialFlow\Envios\ServicioEnvios;
use App\Support\CredentialFlow\Portal\PortalFlag;
use App\Support\CredentialFlow\Portal\ServicioOtp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;

/** Fase 11C.1: interruptor maestro del portal. Desplegar el código no activa el portal: con `portal_enabled` apagado no hay OTP, sesión, panel, descarga ni correo. */
class PortalKillSwitchTest extends PortalTestCase
{
    private function apagar(): void
    {
        config(['credential_flow.portal_enabled' => false]);
    }

    private function sinRastro(): void
    {
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('cf_accesos_otp')->count(), 'ningún desafío OTP');
        $this->assertSame(0, DB::table('cf_envios')->count(), 'ningún envío registrado');
    }

    public function test_el_get_del_portal_apagado_muestra_una_pagina_sencilla_sin_sesion_y_con_cabeceras_seguras(): void
    {
        $this->apagar();

        foreach (['portal.inicio', 'portal.codigo', 'portal.panel'] as $ruta) {
            $r = $this->get(route($ruta));
            $r->assertStatus(503)->assertSee('El portal de certificados aún no está disponible')->assertDontSee('documento');
            $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'), $ruta);
            $this->assertStringContainsString('noindex', (string) $r->headers->get('X-Robots-Tag'), $ruta);
            $this->assertStringContainsString("default-src 'none'", (string) $r->headers->get('Content-Security-Policy'), $ruta);
            $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'), $ruta);
            $this->assertNull($r->headers->getCookies() ? collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === 'cf_portal_session') : null, 'no se crea la sesión del portal');
        }
        $this->get(route('portal.descargar', 1))->assertStatus(503);
        $this->sinRastro();
    }

    public function test_los_post_del_portal_apagado_dan_404_sin_otp_sesion_ni_correo(): void
    {
        $this->apagar();
        config(['credential_flow.correo.habilitado' => true, 'credential_flow.correo.permitir_transporte_log' => true]);

        $this->solicitar('1.000.001', self::CORREO)->assertNotFound();
        $this->validarCodigo('123456')->assertNotFound();
        $this->post(route('portal.reenviar'))->assertNotFound();
        $this->post(route('portal.salir'))->assertNotFound();
        $this->sinRastro();
    }

    public function test_apagar_el_portal_cierra_una_sesion_ya_abierta(): void
    {
        $this->entrar()->assertRedirect(route('portal.panel'));
        $this->get(route('portal.panel'))->assertOk();

        $this->apagar();

        $this->get(route('portal.panel'))->assertStatus(503);
        $this->get(route('portal.descargar', DB::table('cf_certificados_legado')->value('id')))->assertStatus(503);
    }

    public function test_los_servicios_tambien_se_niegan_con_el_portal_apagado(): void
    {
        $this->apagar();

        $this->assertFalse(app(ServicioOtp::class)->solicitar('1.000.001', self::CORREO, '127.0.0.1', 'test'));
        $this->assertNull(app(ServicioEnvios::class)->enviarOtp(1, self::CORREO, '123456'));
        $this->sinRastro();
    }

    public function test_la_verificacion_publica_es_independiente_del_portal(): void
    {
        $this->apagar();

        $r = $this->get('/verificar/12345');
        $this->assertNotSame(503, $r->getStatusCode(), 'la verificación pública no depende del interruptor del portal');
    }

    public function test_con_el_portal_encendido_el_flujo_normal_sigue_intacto(): void
    {
        $this->assertTrue(PortalFlag::habilitado());

        $this->get(route('portal.inicio'))->assertOk()->assertSee('Consulta tus certificados');
        $this->entrar()->assertRedirect(route('portal.panel'));
        $this->get(route('portal.panel'))->assertOk();
        $this->post(route('portal.salir'))->assertRedirect();
        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
    }

    public function test_el_portal_es_independiente_de_los_interruptores_de_identidad(): void
    {
        config(['credential_flow.identidad.decisiones_enabled' => false, 'credential_flow.identidad.multi_scope_enabled' => false, 'credential_flow.identidad.mass_scope_enabled' => false]);

        $this->entrar()->assertRedirect(route('portal.panel'));
        $this->get(route('portal.panel'))->assertOk();
    }

    public function test_sin_la_variable_de_entorno_el_portal_queda_apagado_por_defecto(): void
    {
        $php = PHP_BINARY;
        $p = new Process([$php, 'artisan', 'credential-flow:portal:preflight', '--json'], base_path(), ['CREDENTIAL_FLOW_PORTAL_ENABLED' => false, 'APP_ENV' => 'local']);
        $p->run();
        $salida = json_decode($p->getOutput(), true);

        $this->assertIsArray($salida, $p->getOutput().$p->getErrorOutput());
        $this->assertFalse($salida['portal_enabled']);
        $apagado = collect($salida['checks'])->firstWhere('id', 'portal_enabled');
        $this->assertStringContainsString('APAGADO', $apagado['detalle']);
    }
}
