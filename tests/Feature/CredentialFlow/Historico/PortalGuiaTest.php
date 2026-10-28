<?php

namespace Tests\Feature\CredentialFlow\Historico;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Guía «¿Cómo descargar mi certificado?» (/certificados/guia): pública, sin sesión del portal, bajo la misma CSP estricta (nonce, sin estilos ni
 * manejadores en línea, sin recursos externos) y solo con datos ficticios. El inicio del portal la enlaza. Datos sintéticos.
 */
class PortalGuiaTest extends PortalTestCase
{
    private const CONTACTO = 'https://api.whatsapp.com/send?phone=570000000000';

    private function guia(): TestResponse
    {
        return $this->get(route('portal.guia'));
    }

    public function test_la_guia_es_publica_no_crea_sesion_ni_otp_y_lleva_las_cabeceras_seguras_del_portal(): void
    {
        $r = $this->guia()->assertOk();

        foreach (['Descarga tu certificado en', '¡Vamos por tu certificado!', 'Cuéntanos quién eres', '¡Tienes un mensaje!', '¡Ya casi estamos!',
            '¡Aquí están tus certificados!', '¡Listo! Tu certificado te espera', '¡Felicitaciones!', '¿Necesitas ayuda?'] as $texto) {
            $r->assertSee($texto, false);
        }
        $this->assertStringContainsString("default-src 'none'", (string) $r->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', (string) $r->headers->get('X-Robots-Tag'));
        $this->assertSame([], $r->headers->getCookies(), 'la guía no abre la sesión del portal ni deja cookies (tampoco XSRF)');
        $this->assertSame(0, DB::table('cf_accesos_otp')->count());
    }

    public function test_la_guia_cumple_la_csp_estricta_sin_estilos_ni_manejadores_en_linea_ni_recursos_externos(): void
    {
        $r = $this->guia()->assertOk();
        $html = (string) $r->getContent();
        $this->assertSame(1, preg_match("/'nonce-([^']+)'/", (string) $r->headers->get('Content-Security-Policy'), $m));

        preg_match_all('/<(style|script)\b[^>]*>/i', $html, $etiquetas);
        $this->assertNotEmpty($etiquetas[0]);
        foreach ($etiquetas[0] as $etiqueta) {
            $this->assertStringContainsString('nonce="'.$m[1].'"', $etiqueta, 'toda etiqueta <style>/<script> lleva el nonce: '.$etiqueta);
        }
        $this->assertSame(0, preg_match('/\sstyle\s*=/i', $html), 'sin atributos style (la CSP no permite estilos en línea)');
        $this->assertSame(0, preg_match('/\son[a-z]+\s*=/i', $html), 'sin manejadores de eventos en línea');
        $this->assertSame(0, preg_match('/(?:src|href)\s*=\s*"(?:https?:)?\/\/(?!localhost)/i', preg_replace('/<a\s[^>]*>/i', '', $html)), 'sin imágenes, hojas ni scripts externos');
    }

    public function test_las_imagenes_de_la_guia_existen_en_public(): void
    {
        preg_match_all('/src="(\/images\/[^"]+)"/', (string) $this->guia()->getContent(), $m);

        $this->assertContains('/images/ovi/ovi-saluda.png', $m[1]);
        $this->assertContains('/images/ovi/ovi-telefono.png', $m[1]);
        foreach (array_unique($m[1]) as $ruta) {
            $this->assertFileExists(public_path(ltrim($ruta, '/')), $ruta);
        }
    }

    public function test_la_guia_muestra_la_direccion_real_del_portal_y_enlaza_a_la_consulta(): void
    {
        $direccion = preg_replace('#^https?://#i', '', route('portal.inicio'));

        $this->guia()->assertOk()
            ->assertSee('<span class="url-txt" data-direccion>'.collect(explode('/', $direccion))->map(fn ($s) => '<span class="nw">'.e($s).'</span>')->implode('/<wbr>').'</span>', false)
            ->assertSee('data-copiar="'.route('portal.inicio').'"', false)
            ->assertSee('href="'.route('portal.inicio').'"', false);
    }

    public function test_la_guia_solo_usa_datos_ficticios_aunque_haya_una_sesion_abierta(): void
    {
        $this->entrar()->assertRedirect(route('portal.panel'));

        $this->guia()->assertOk()
            ->assertSee('ana.ejemplo@correo.com')->assertSee('1023456789')->assertSee('Código de ejemplo')
            ->assertDontSee(self::NOMBRE)->assertDontSee(self::DOCUMENTO)->assertDontSee(self::CORREO)
            ->assertDontSee('Cerrar sesión</button>', false);
    }

    public function test_el_contacto_por_whatsapp_aparece_solo_si_esta_configurado(): void
    {
        config(['credential_flow.portal.contacto_url' => null]);
        $this->guia()->assertOk()->assertSee('mailto:contactenos@fycconsultores.com', false)->assertDontSee('whatsapp', false);

        config(['credential_flow.portal.contacto_url' => self::CONTACTO]);
        $this->guia()->assertOk()->assertSee('href="'.self::CONTACTO.'"', false)->assertSee('mailto:contactenos@fycconsultores.com', false);
    }

    public function test_el_inicio_ofrece_el_boton_de_la_guia(): void
    {
        $this->get(route('portal.inicio'))->assertOk()
            ->assertSee('data-guia-enlace', false)
            ->assertSee('href="'.route('portal.guia').'"', false)
            ->assertSee('¿Cómo descargar mi certificado?');
    }

    public function test_con_el_portal_apagado_la_guia_tampoco_esta_disponible(): void
    {
        config(['credential_flow.portal_enabled' => false]);

        $this->guia()->assertStatus(503)->assertSee('El portal de certificados aún no está disponible')->assertDontSee('¡Vamos por tu certificado!');
    }
}
