<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Support\CredentialFlow\Portal\VerificadorSmtp;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Fase 11C.1: comprobación de SMTP (sin conectar ni enviar) y diagnóstico `credential-flow:portal:preflight` (solo lectura). */
class VerificadorSmtpYPreflightPortalTest extends TestCase
{
    private function smtp(array $m = [], array $from = ['address' => 'certificados@fycconsultores.com', 'name' => 'F&C'], string $default = 'smtp'): void
    {
        config([
            'mail.default' => $default,
            'mail.mailers.smtp' => array_merge(['transport' => 'smtp', 'host' => 'smtp.fycconsultores.com', 'port' => 587, 'scheme' => 'tls', 'username' => 'usuario', 'password' => 'clave'], $m),
            'mail.from' => $from,
            'credential_flow.correo.habilitado' => true,
            'credential_flow.correo.permitir_transporte_log' => false,
            'credential_flow.correo.remitente.direccion' => null,
        ]);
    }

    public function test_una_configuracion_real_es_apta(): void
    {
        $this->smtp();
        $r = VerificadorSmtp::evaluar();

        $this->assertSame(['SMTP_APTO', true, []], [$r['estado'], $r['apto'], $r['problemas']]);
    }

    public function test_los_placeholders_de_desarrollo_no_son_aptos(): void
    {
        foreach ([['host' => 'mailpit', 'port' => 1025, 'scheme' => null, 'username' => null, 'password' => null], ['host' => 'MailHog'], ['host' => 'localhost'], ['host' => '127.0.0.1'], ['host' => 'smtp.example.com']] as $m) {
            $this->smtp($m);
            $r = VerificadorSmtp::evaluar();
            $this->assertFalse($r['apto'], json_encode($m));
            $this->assertContains('SMTP_HOST_DESARROLLO', $r['problemas']);
        }
    }

    public function test_host_vacio_puerto_invalido_y_remitentes_de_ejemplo_no_son_aptos(): void
    {
        $this->smtp(['host' => '']);
        $this->assertContains('SMTP_HOST_VACIO', VerificadorSmtp::evaluar()['problemas']);

        $this->smtp(['port' => 0]);
        $this->assertContains('SMTP_PUERTO_INVALIDO', VerificadorSmtp::evaluar()['problemas']);

        foreach (['hello@example.com', 'a@mail.example.org', 'x@servidor.test'] as $from) {
            $this->smtp([], ['address' => $from, 'name' => 'x']);
            $this->assertContains('REMITENTE_DE_EJEMPLO', VerificadorSmtp::evaluar()['problemas'], $from);
        }
        $this->smtp([], ['address' => 'no-es-un-correo', 'name' => 'x']);
        $this->assertContains('REMITENTE_INVALIDO', VerificadorSmtp::evaluar()['problemas']);
    }

    public function test_los_mailers_que_no_entregan_y_el_modulo_deshabilitado_no_son_aptos(): void
    {
        foreach (['log', 'array'] as $mailer) {
            $this->smtp([], default: $mailer);
            $r = VerificadorSmtp::evaluar();
            $this->assertContains('MAILER_NO_ENTREGA_CORREO', $r['problemas'], $mailer);
            $this->assertSame('SMTP_NO_APTO', $r['estado']);
        }
        $this->smtp();
        config(['credential_flow.correo.habilitado' => false]);
        $this->assertContains('ENVIO_DEL_MODULO_DESHABILITADO', VerificadorSmtp::evaluar()['problemas']);

        $this->smtp();
        config(['mail.default' => 'inexistente']);
        $this->assertContains('MAILER_NO_CONFIGURADO', VerificadorSmtp::evaluar()['problemas']);
    }

    public function test_un_failover_con_un_miembro_no_apto_no_es_apto(): void
    {
        $this->smtp();
        config(['mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']]]);
        config(['mail.default' => 'failover']);

        $this->assertContains('MAILER_NO_ENTREGA_CORREO', VerificadorSmtp::evaluar()['problemas']);
    }

    public function test_sin_credenciales_o_con_transporte_log_permitido_solo_advierte_y_no_expone_secretos(): void
    {
        $this->smtp(['username' => null, 'password' => null]);
        config(['credential_flow.correo.permitir_transporte_log' => true]);
        $r = VerificadorSmtp::evaluar();

        $this->assertTrue($r['apto']);
        $this->assertEqualsCanonicalizing(['SMTP_SIN_CREDENCIALES', 'TRANSPORTE_LOG_PERMITIDO'], $r['advertencias']);
        $this->assertFalse($r['smtp']['usuario_presente']);

        $this->smtp(['username' => 'UsuarioSecreto77', 'password' => 'ValorSecreto99']);
        $json = json_encode(VerificadorSmtp::evaluar());
        $this->assertStringNotContainsString('UsuarioSecreto77', $json);
        $this->assertStringNotContainsString('ValorSecreto99', $json);
    }

    public function test_el_diagnostico_no_envia_correo_y_sale_con_1_si_el_smtp_es_un_placeholder(): void
    {
        Mail::fake();
        $this->smtp(['host' => 'mailpit', 'port' => 1025, 'scheme' => null, 'username' => null, 'password' => null], ['address' => 'hello@example.com', 'name' => 'x']);

        $this->artisan('credential-flow:portal:preflight', ['--json' => true])->expectsOutputToContain('"listo_para_activar":false')->assertExitCode(1);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_el_diagnostico_en_json_informa_el_estado_del_portal_y_los_bloqueos(): void
    {
        $this->smtp();
        config(['credential_flow.portal_enabled' => false, 'app.url' => 'https://fycconsultores.com', 'credential_flow.verificacion.base_url' => 'https://fycconsultores.com']);

        Artisan::call('credential-flow:portal:preflight', ['--json' => true]);
        $r = json_decode(Artisan::output(), true);

        $this->assertFalse($r['portal_enabled']);
        $this->assertNotContains('smtp', $r['bloqueos']);
        $this->assertNotContains('url_verificacion', $r['bloqueos']);
        $this->assertSame(['portal_enabled', 'smtp', 'app_key', 'sesion', 'https', 'url_verificacion'], array_slice(array_column($r['checks'], 'id'), 0, 6));
    }
}
