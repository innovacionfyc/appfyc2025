<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Mail\CodigoAccesoMail;
use Illuminate\Support\Facades\Mail;

/**
 * Fase 12B.1: soporte por WhatsApp visible en las pantallas del portal (configurable con CREDENTIAL_FLOW_PORTAL_CONTACTO_URL), mensaje uniforme
 * tras solicitar el OTP y aviso de Spam en el correo. Nada de esto depende de si el documento existe. Datos sintéticos.
 */
class PortalSoporteUxTest extends PortalTestCase
{
    private const CONTACTO = 'https://api.whatsapp.com/send?phone=570000000000&text=Indique%20cual%20es%20el%20error%20que%20presenta';

    private const TEXTO_SOPORTE = '¿Inconvenientes para acceder o descargar el certificado?';

    public function test_el_soporte_aparece_en_inicio_codigo_y_panel_cuando_hay_contacto_configurado(): void
    {
        config(['credential_flow.portal.contacto_url' => self::CONTACTO]);

        $this->get(route('portal.inicio'))->assertOk()->assertSee(self::TEXTO_SOPORTE, false)->assertSee('data-soporte="whatsapp"', false)->assertSee('api.whatsapp.com/send?phone=570000000000', false);

        $this->solicitar('9999999', 'nadie@example.test');
        $this->get(route('portal.codigo'))->assertOk()->assertSee(self::TEXTO_SOPORTE, false)->assertSee('Contactar por WhatsApp.', false);

        $this->post(route('portal.salir'));
        $this->entrar()->assertRedirect(route('portal.panel'));
        $this->get(route('portal.panel'))->assertOk()->assertSee(self::TEXTO_SOPORTE, false);
    }

    public function test_el_soporte_aparece_en_el_estado_limitado_y_en_el_portal_no_disponible(): void
    {
        config(['credential_flow.portal.contacto_url' => self::CONTACTO, 'credential_flow.portal.limite_solicitud_por_minuto' => 1]);
        $this->solicitar('9999999', 'a@example.test');
        $limitada = $this->solicitar('9999998', 'a@example.test');
        $limitada->assertStatus(429)->assertSee('Demasiados intentos')->assertSee(self::TEXTO_SOPORTE, false)->assertSee('api.whatsapp.com', false);

        config(['credential_flow.portal_enabled' => false]);
        $this->get(route('portal.inicio'))->assertStatus(503)->assertSee(self::TEXTO_SOPORTE, false)->assertSee('api.whatsapp.com', false);
    }

    public function test_sin_contacto_configurado_no_se_muestra_ningun_enlace_ni_se_inventa_uno(): void
    {
        config(['credential_flow.portal.contacto_url' => null]);

        $this->get(route('portal.inicio'))->assertOk()->assertDontSee('data-soporte', false)->assertDontSee('whatsapp', false);
    }

    public function test_el_soporte_no_cambia_la_uniformidad_de_la_respuesta(): void
    {
        config(['credential_flow.portal.contacto_url' => self::CONTACTO, 'credential_flow.portal.limite_solicitud_por_minuto' => 100]);
        $paginas = [];
        foreach ([['1.000.001', 'ana.uno@example.test'], ['9999999', 'nadie@example.test']] as [$doc, $correo]) {
            $this->post(route('portal.salir'));
            $r = $this->solicitar($doc, $correo);
            $r->assertRedirect(route('portal.codigo'))->assertSessionHas('aviso', 'Si los datos coinciden con los registros, se envió un código de acceso al correo asociado. Revisar la bandeja de entrada y, si no aparece, verificar también Spam o Correo no deseado.');
            $paginas[] = $this->get(route('portal.codigo'))->getContent();
        }

        $this->assertSame($this->sinToken($paginas[0]), $this->sinToken($paginas[1]));
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8">'.$paginas[0]);
        foreach (['style', 'script', 'a'] as $etiqueta) {
            while (($nodo = $dom->getElementsByTagName($etiqueta)->item(0)) !== null) {
                $nodo->parentNode->removeChild($nodo);
            }
        }
        $this->assertStringNotContainsString('@', $dom->textContent, 'La pantalla no debe mostrar ningún correo');
    }

    public function test_el_correo_otp_incluye_la_instruccion_y_el_aviso_de_spam_sin_datos_personales(): void
    {
        $mail = new CodigoAccesoMail('123456');
        $html = $mail->render();
        $texto = view('emails.credential-flow.codigo-acceso-texto', ['codigo' => '123456', 'vigencia' => 10, 'entidad' => 'F&C Consultores S.A.S.'])->render();

        foreach ([$html, $texto] as $cuerpo) {
            $this->assertStringContainsString('123456', $cuerpo);
            $this->assertStringContainsString('consultar y descargar certificados', $cuerpo);
            $this->assertStringContainsString('Spam o Correo no deseado', $cuerpo);
            $this->assertStringNotContainsString('1000001', $cuerpo);
        }
        $this->assertStringNotContainsString('&amp;', $texto, 'El texto plano no debe llevar entidades HTML');
        $this->assertSame('Código de acceso para consultar certificados', $mail->envelope()->subject);
        Mail::assertNothingSent();
    }

    private function sinToken(string $html): string
    {
        return (string) preg_replace('/(name="_token" value=")[^"]+/', '$1X', preg_replace('/nonce="[^"]+"/', 'nonce=""', $html));
    }
}
