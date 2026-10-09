<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Http\Middleware\IniciarSesionPortal;
use App\Mail\CodigoAccesoMail;
use App\Support\CredentialFlow\Portal\Hmac;
use App\Support\CredentialFlow\Portal\ServicioOtp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;

/** Portal público · Fase 7: privacidad de la respuesta inicial, OTP, correo (fake), límites, sesión y cabeceras. Datos sintéticos. */
class PortalOtpTest extends PortalTestCase
{
    // ── Privacidad: la respuesta inicial es idéntica ──────────────────────────

    /** @return array<string,array{0:string,1:string,2:bool}> documento, correo, ¿debe enviarse OTP? */
    public static function casosIniciales(): array
    {
        return [
            'documento existe + correo incorrecto' => ['1.000.001', 'otra.persona@example.test', false],
            'documento inexistente' => ['9999999', 'ana.uno@example.test', false],
            'documento en revisión' => ['ABC123', 'diego@example.test', false],
            'correo inválido en la base' => ['1000002', 'correo-invalido', false],
            'documento sin correo' => ['1000003', 'ana.uno@example.test', false],
            'solo conflictivo (sin ok)' => ['2000002', 'zzz@example.test', false],
            'correo válido' => ['1.000.001', 'ana.uno@example.test', true],
            'segundo correo válido (mayúsculas y espacios)' => ['  1 000 001 ', '  ANA.DOS@Example.TEST ', true],
            'duplicado idéntico' => ['2.000.001', 'gina@example.test', true],
            'formato basura' => ['--', 'no-es-correo', false],
        ];
    }

    #[DataProvider('casosIniciales')]
    public function test_la_respuesta_inicial_es_uniforme_y_solo_se_envia_otp_cuando_corresponde(string $doc, string $correo, bool $envia): void
    {
        $r = $this->solicitar($doc, $correo);

        $r->assertRedirect(route('portal.codigo'))->assertSessionHas('aviso', 'Si los datos coinciden con nuestros registros, te enviaremos un código al correo indicado.');
        $envia ? Mail::assertSent(CodigoAccesoMail::class, 1) : Mail::assertNothingSent();
    }

    public function test_el_html_de_la_pantalla_siguiente_es_identico_haya_o_no_coincidencia_y_no_filtra_nada(): void
    {
        config(['credential_flow.portal.limite_solicitud_por_minuto' => 100]);
        $paginas = [];
        foreach (self::casosIniciales() as [$doc, $correo]) {
            $this->post(route('portal.salir'));
            $this->solicitar($doc, $correo);
            $r = $this->get(route('portal.codigo'))->assertOk();
            $paginas[] = $this->normalizar($r);
            $html = $r->getContent();
            foreach ([self::NOMBRE, self::CORREO, self::CORREO2, 'hugo@', 'diego@', '1000001', '2000001', 'ABC123', 'Curso '] as $prohibido) {
                $this->assertStringNotContainsStringIgnoringCase($prohibido, $html, "Filtra {$prohibido}");
            }
        }
        $this->assertCount(1, array_unique(array_map('serialize', array_map(fn ($p) => [$p[0], $p[2]], $paginas))), 'Todas las pantallas siguientes son idénticas');
    }

    public function test_un_caso_valido_y_uno_invalido_difieren_poco_en_tiempo(): void
    {
        $t = function (string $doc, string $correo) {
            $a = hrtime(true);
            $this->solicitar($doc, $correo);

            return (hrtime(true) - $a) / 1e6;
        };
        $t('1000001', self::CORREO); // calentar
        RateLimiter::clear(md5('cf-portal-solicitud'.'cf-portal-sol-min:127.0.0.1'));
        $valido = $t('1000001', 'ana.dos@example.test');
        $invalido = $t('9999999', 'nadie@example.test');

        // El envío ocurre tras responder y el trabajo (bcrypt) es equivalente: la diferencia no es de órdenes de magnitud.
        $this->assertLessThan(max(8 * $invalido, 400), $valido);
    }

    public function test_el_documento_y_el_correo_son_obligatorios_y_no_se_expone_nada_mas(): void
    {
        $this->post(route('portal.solicitar'), ['documento' => '', 'correo' => ''])->assertSessionHasErrors(['documento', 'correo']);
        Mail::assertNothingSent();
    }

    // ── Matching documento + correo ───────────────────────────────────────────

    public function test_cualquiera_de_los_correos_validos_del_documento_sirve_aunque_no_sea_el_del_certificado_principal(): void
    {
        $this->assertSame(2, DB::table('cf_correos')->where('certificado_legado_id', $this->idCert(1))->where('estado', 'valido')->count());

        $this->entrar('1000001', self::CORREO2)->assertRedirect(route('portal.panel'));
        $this->get(route('portal.panel'))->assertOk();
    }

    public function test_el_correo_de_otro_certificado_del_mismo_documento_tambien_sirve(): void
    {
        // Correo registrado solo en el certificado 16 del mismo documento (no en el 1).
        DB::table('cf_correos')->insert(['certificado_legado_id' => $this->idCert(16), 'correo' => 'extra@example.test', 'correo_normalizado' => 'extra@example.test', 'estado' => 'valido', 'orden' => 9, 'es_principal' => false, 'origen' => 'legado', 'created_at' => now(), 'updated_at' => now()]);

        $this->solicitar('1000001', 'extra@example.test');

        Mail::assertSent(CodigoAccesoMail::class, 1);
    }

    public function test_un_correo_marcado_invalido_nunca_habilita(): void
    {
        DB::table('cf_correos')->where('certificado_legado_id', $this->idCert(1))->where('correo_normalizado', self::CORREO2)->update(['estado' => 'invalido', 'es_principal' => false]);

        $this->solicitar('1000001', self::CORREO2);

        Mail::assertNothingSent();
    }

    public function test_un_documento_solo_con_certificados_bloqueados_no_recibe_otp_aunque_el_correo_coincida(): void
    {
        foreach ([['ABC123', 'diego@example.test'], ['2000002', 'hugo@example.test'], ['1000003', 'ana.uno@example.test']] as $i => [$doc, $correo]) {
            if ($doc === '2000002') {
                DB::table('cf_certificados_legado')->where('id', $this->idCert(24))->update(['conciliacion_estado' => 'pendiente_conciliacion']);
            }
            $this->solicitar($doc, $correo);
        }

        Mail::assertNothingSent();
    }

    // ── Correo ────────────────────────────────────────────────────────────────

    public function test_el_correo_va_al_destinatario_correcto_con_asunto_codigo_y_sin_datos_innecesarios(): void
    {
        $this->solicitar('1.000.001', self::CORREO2);

        Mail::assertSent(CodigoAccesoMail::class, function (CodigoAccesoMail $m) {
            $html = $m->render();

            return $m->hasTo(self::CORREO2) && $m->envelope()->subject === 'Código para consultar tus certificados'
                && preg_match('/^\d{6}$/', $m->codigo) === 1 && str_contains($html, $m->codigo) && str_contains($html, '10 minutos') && str_contains($html, 'ignora este mensaje')
                && ! str_contains($html, self::NOMBRE) && ! str_contains($html, '1000001') && ! str_contains($html, 'Curso') && $m->attachments === [];
        });
    }

    // ── OTP ───────────────────────────────────────────────────────────────────

    public function test_otp_correcto_abre_el_panel_y_no_se_puede_reutilizar(): void
    {
        $this->solicitar('1000001', self::CORREO);
        $codigo = $this->ultimoCodigo();

        $this->validarCodigo($codigo)->assertRedirect(route('portal.panel'));
        $this->post(route('portal.salir'));
        $this->solicitar('1000001', self::CORREO)->assertSessionHas('aviso');
        $this->validarCodigo($codigo)->assertRedirect(route('portal.codigo'))->assertSessionHas('error', 'El código no es válido o ya expiró.');
        $this->assertNotNull(DB::table('cf_accesos_otp')->whereNotNull('usado_at')->first());
    }

    public function test_otp_incorrecto_da_el_mensaje_uniforme(): void
    {
        $this->solicitar('1000001', self::CORREO);
        $malo = $this->ultimoCodigo() === '000000' ? '111111' : '000000';

        $this->validarCodigo($malo)->assertRedirect(route('portal.codigo'))->assertSessionHas('error', 'El código no es válido o ya expiró.');
        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
    }

    public function test_otp_expirado_da_exactamente_el_mismo_mensaje(): void
    {
        $this->solicitar('1000001', self::CORREO);
        $codigo = $this->ultimoCodigo();

        $this->travel(11)->minutes();
        $r = $this->validarCodigo($codigo);

        $r->assertRedirect(route('portal.codigo'))->assertSessionHas('error', 'El código no es válido o ya expiró.');
        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
    }

    public function test_el_otp_vive_exactamente_diez_minutos(): void
    {
        $this->solicitar('1000001', self::CORREO);
        $codigo = $this->ultimoCodigo();
        $this->travel(9)->minutes();

        $this->validarCodigo($codigo)->assertRedirect(route('portal.panel'));
    }

    public function test_cinco_intentos_fallidos_bloquean_el_desafio_aunque_despues_se_envie_el_correcto(): void
    {
        $this->solicitar('1000001', self::CORREO);
        $bueno = $this->ultimoCodigo();
        $malo = $bueno === '123456' ? '654321' : '123456';
        for ($i = 0; $i < 5; $i++) {
            $this->validarCodigo($malo)->assertSessionHas('error');
        }

        $this->validarCodigo($bueno)->assertRedirect(route('portal.codigo'))->assertSessionHas('error', 'El código no es válido o ya expiró.');
        $fila = DB::table('cf_accesos_otp')->first();
        $this->assertNotNull($fila->bloqueado_at);
        $this->assertSame(5, (int) $fila->intentos);
    }

    public function test_un_otp_nuevo_invalida_el_anterior(): void
    {
        $this->solicitar('1000001', self::CORREO);
        $viejo = $this->ultimoCodigo();
        $this->travel(61)->seconds();
        $this->solicitar('1000001', self::CORREO);
        $nuevo = $this->ultimoCodigo();
        $this->assertNotSame($viejo, $nuevo);

        $this->assertSame(1, DB::table('cf_accesos_otp')->whereNotNull('invalidado_at')->count());
        $this->validarCodigo($viejo === $nuevo ? '000000' : $viejo)->assertSessionHas('error');
        $this->validarCodigo($nuevo)->assertRedirect(route('portal.panel'));
    }

    public function test_el_codigo_son_seis_digitos_con_ceros_a_la_izquierda_y_se_genera_con_random_int(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', ServicioOtp::generarCodigo());
        }
        $fuente = file_get_contents(app_path('Support/CredentialFlow/Portal/ServicioOtp.php'));
        $this->assertStringContainsString('random_int(0, 999_999)', $fuente);
        $this->assertDoesNotMatchRegularExpression('/\b(mt_rand|rand|mt_srand|uniqid)\s*\(/', $fuente);
        foreach (['000000', '123', '12345a', '1234567', ''] as $raro) {
            $this->solicitar('1000001', self::CORREO);
            $this->validarCodigo($raro)->assertSessionHas('error');
        }
    }

    public function test_en_la_base_no_hay_ningun_dato_en_claro_solo_hashes(): void
    {
        $this->solicitar('1.000.001', self::CORREO);
        $codigo = $this->ultimoCodigo();
        $fila = (array) DB::table('cf_accesos_otp')->first();

        $this->assertSame(Hmac::de('documento', '1000001'), $fila['documento_hash']);
        $this->assertSame(Hmac::de('correo', self::CORREO), $fila['correo_hash']);
        $this->assertTrue(Hash::check($codigo, $fila['otp_hash']));
        $this->assertNotSame($codigo, $fila['otp_hash']);
        $volcado = json_encode($fila);
        foreach ([$codigo, self::CORREO, '1000001', '127.0.0.1'] as $claro) {
            $this->assertStringNotContainsString($claro, $volcado);
        }
        $this->assertSame(Hmac::ip('127.0.0.1'), $fila['ip_hash']);
        $this->assertSame(64, strlen($fila['ip_hash']));
    }

    public function test_no_se_registra_en_logs_ni_documento_ni_correo_ni_codigo(): void
    {
        Log::spy();

        $this->solicitar('1000001', self::CORREO);
        $this->validarCodigo('000000');
        $this->solicitar('9999999', 'nadie@example.test');

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
    }

    // ── Reenvío, límites y bloqueo ────────────────────────────────────────────

    public function test_no_se_reenvia_antes_de_60_segundos_y_si_despues(): void
    {
        $this->solicitar('1000001', self::CORREO);
        $this->travel(30)->seconds();
        $this->post(route('portal.reenviar'))->assertRedirect(route('portal.codigo'))->assertSessionHas('aviso', 'Si los datos coinciden con nuestros registros, te enviaremos un código al correo indicado.');
        Mail::assertSent(CodigoAccesoMail::class, 1);

        $this->travel(31)->seconds();
        $this->post(route('portal.reenviar'));
        Mail::assertSent(CodigoAccesoMail::class, 2);
    }

    public function test_maximo_tres_otp_por_hora_por_documento_y_correo(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->travel(61)->seconds();
            $this->solicitar('1000001', self::CORREO);
        }

        Mail::assertSent(CodigoAccesoMail::class, 3);
    }

    public function test_maximo_ocho_otp_por_dia_por_documento(): void
    {
        $emails = [self::CORREO, self::CORREO2];
        for ($i = 0; $i < 12; $i++) {
            $this->travel(21)->minutes(); // evita el tope por hora de cada correo
            $this->solicitar('1000001', $emails[$i % 2]);
            RateLimiter::clear(md5('cf-portal-solicitud'.'cf-portal-sol-min:127.0.0.1'));
        }

        Mail::assertSent(CodigoAccesoMail::class, 8);
    }

    public function test_tras_tres_desafios_bloqueados_no_se_emiten_otp_durante_30_minutos_y_despues_si(): void
    {
        for ($n = 0; $n < 3; $n++) {
            $this->travel(61)->seconds();
            $this->solicitar('1000001', $n % 2 === 0 ? self::CORREO : self::CORREO2);
            $bueno = $this->ultimoCodigo();
            for ($i = 0; $i < 5; $i++) {
                $this->validarCodigo($bueno === '000000' ? '111111' : '000000');
            }
        }
        $this->assertSame(3, DB::table('cf_accesos_otp')->whereNotNull('bloqueado_at')->count());
        Mail::fake();

        $this->travel(5)->minutes();
        $this->solicitar('1000001', self::CORREO)->assertRedirect(route('portal.codigo'));
        Mail::assertNothingSent();

        $this->travel(31)->minutes();
        $this->solicitar('1000001', self::CORREO);
        Mail::assertSent(CodigoAccesoMail::class, 1);
    }

    public function test_limite_por_ip_de_solicitud_validacion_y_descarga_estan_separados(): void
    {
        config(['credential_flow.portal.limite_solicitud_por_minuto' => 2, 'credential_flow.portal.limite_validacion_por_minuto' => 3]);
        $this->solicitar('9999999', 'a@example.test');
        $this->solicitar('9999998', 'a@example.test');
        $r = $this->solicitar('9999997', 'a@example.test');

        $r->assertStatus(429)->assertSee('Demasiados intentos');
        $this->assertGreaterThanOrEqual(1, (int) $r->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        // La validación sigue disponible (límite propio).
        $this->validarCodigo('000000');
        $this->assertSame(1, RateLimiter::attempts(md5('cf-portal-validacion'.'cf-portal-val:127.0.0.1')));
        $this->assertSame(0, RateLimiter::attempts(md5('cf-portal-descarga'.'cf-portal-desc:127.0.0.1')));
    }

    public function test_limite_de_validacion_responde_429(): void
    {
        config(['credential_flow.portal.limite_validacion_por_minuto' => 2]);
        $this->solicitar('1000001', self::CORREO);
        $this->validarCodigo('000000');
        $this->validarCodigo('000001');

        $this->validarCodigo('000002')->assertStatus(429);
    }

    // ── Sesión pública ────────────────────────────────────────────────────────

    public function test_acceso_al_panel_y_a_la_descarga_sin_otp_redirige_al_inicio(): void
    {
        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
        $this->get(route('portal.descargar', $this->idCert(1)))->assertRedirect(route('portal.inicio'));
    }

    public function test_se_regenera_el_id_de_sesion_al_autenticar_contra_fijacion(): void
    {
        $this->solicitar('1000001', self::CORREO);
        $antes = session()->getId();

        $this->validarCodigo($this->ultimoCodigo())->assertRedirect(route('portal.panel'));

        $this->assertNotSame($antes, session()->getId());
    }

    public function test_la_sesion_caduca_a_los_30_minutos_de_inactividad(): void
    {
        $this->entrar();
        $this->get(route('portal.panel'))->assertOk();
        $this->travel(29)->minutes();
        $this->get(route('portal.panel'))->assertOk();   // renueva la inactividad
        $this->travel(29)->minutes();
        $this->get(route('portal.panel'))->assertOk();
        $this->travel(31)->minutes();

        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'))->assertSessionHas('aviso');
    }

    public function test_la_sesion_caduca_a_las_2_horas_aunque_haya_actividad(): void
    {
        $this->entrar();
        for ($i = 0; $i < 3; $i++) {
            $this->travel(29)->minutes();
            $this->get(route('portal.panel'))->assertOk();
        }
        $this->travel(29)->minutes();
        $this->get(route('portal.panel'))->assertOk();   // 116 min
        $this->travel(10)->minutes();                      // 126 min

        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
    }

    public function test_cerrar_sesion_invalida_el_contexto_y_vuelve_a_la_portada(): void
    {
        $this->entrar();
        $antes = session()->getId();

        $this->post(route('portal.salir'))->assertRedirect(route('portal.inicio'));

        $this->assertNotSame($antes, session()->getId());
        $this->assertFalse(session()->has('cf_portal.contexto'));
        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
    }

    public function test_la_sesion_publica_no_es_un_usuario_ni_da_acceso_administrativo(): void
    {
        $this->entrar();

        $this->assertGuest();
        $this->get('/admin/credential-flow/historico')->assertRedirect(route('login'));
        $this->get('/admin/credential-flow/historico/certificados/'.$this->idCert(1).'/pdf')->assertRedirect(route('login'));
    }

    public function test_cookie_propia_del_portal_httponly_y_solo_para_su_ruta(): void
    {
        config(['session.driver' => 'file', 'session.files' => sys_get_temp_dir()]);
        $r = $this->get(route('portal.inicio'));
        $cookie = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === IniciarSesionPortal::COOKIE);

        $this->assertNotNull($cookie, 'Cookie propia del portal');
        $this->assertSame('/certificados', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNotSame(config('session.cookie'), $cookie->getName());
        $this->assertSame(config('session.cookie') !== IniciarSesionPortal::COOKIE, true);
    }

    // ── Cabeceras y CSRF ──────────────────────────────────────────────────────

    public function test_todas_las_pantallas_llevan_cabeceras_estrictas(): void
    {
        $this->solicitar('1000001', self::CORREO);
        $respuestas = [$this->get(route('portal.inicio')), $this->get(route('portal.codigo')), $this->solicitar('1000001', self::CORREO)];
        $this->validarCodigo($this->ultimoCodigo());
        $respuestas[] = $this->get(route('portal.panel'));

        foreach ($respuestas as $r) {
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
            $this->assertStringContainsString('noindex', $r->headers->get('X-Robots-Tag'));
            $this->assertSame('no-referrer', $r->headers->get('Referrer-Policy'));
            $this->assertStringContainsString("default-src 'none'", $r->headers->get('Content-Security-Policy'));
            $this->assertStringContainsString("form-action 'self'", $r->headers->get('Content-Security-Policy'));
            $this->assertSame('DENY', $r->headers->get('X-Frame-Options'));
        }
    }

    public function test_los_post_exigen_csrf_sin_excepciones(): void
    {
        $this->app['env'] = 'production-like'; // el CSRF solo se omite cuando la app corre como pruebas unitarias
        foreach (['portal.solicitar', 'portal.validar', 'portal.reenviar', 'portal.salir'] as $ruta) {
            $this->post(route($ruta), ['documento' => '1000001', 'correo' => self::CORREO, 'codigo' => '000000'])->assertStatus(419);
        }
        Mail::assertNothingSent();
        $this->assertNotContains('certificados/*', config('app.csrf_except', []));
    }

    public function test_el_formulario_incluye_el_token_csrf(): void
    {
        $this->get(route('portal.inicio'))->assertSee('name="_token"', false);
    }

    public function test_mensajes_humanos_sin_jerga_tecnica(): void
    {
        $this->solicitar('1000001', self::CORREO);
        $this->validarCodigo('000000');
        $html = $this->get(route('portal.codigo'))->getContent();

        foreach (['SQLSTATE', 'constraint', 'token', 'hash', 'conciliacion', 'plantilla_id', 'Exception'] as $jerga) {
            $this->assertStringNotContainsStringIgnoringCase($jerga === 'token' ? 'token_hash' : $jerga, $html);
        }
    }
}
