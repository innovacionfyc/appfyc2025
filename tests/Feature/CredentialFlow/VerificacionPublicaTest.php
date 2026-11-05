<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Support\CredentialFlow\Emisiones\CodigoEmision;
use App\Support\CredentialFlow\Verificacion\UrlVerificacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/** F&C Credential Flow · Fase 8: verificación pública por código (sin login, sin PII de más, sin cookies). */
class VerificacionPublicaTest extends EmisionesTestCase
{
    private const MOTIVO = 'Motivo administrativo reservado XYZ';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('cf-verificacion-fallos:127.0.0.1');
    }

    private function emitida(): Emision
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->firstOrFail();
        $this->emitirPor($lote, $p)->assertCreated();

        return Emision::firstOrFail();
    }

    private function revocar(Emision $e): void
    {
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e), ['motivo' => self::MOTIVO])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    /** Sin sesión de administrador: el visitante es anónimo. */
    private function publica(string $codigo): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->get('/verificar/'.$codigo);
    }

    private function normalizar(TestResponse $r): array
    {
        $cabeceras = $r->headers->all();
        unset($cabeceras['date'], $cabeceras['x-ratelimit-remaining']); // el contador del limitador no depende del código
        $cabeceras['content-security-policy'] = preg_replace("/'nonce-[^']+'/", "'nonce-N'", $cabeceras['content-security-policy']);

        return [preg_replace('/nonce="[^"]+"/', 'nonce="N"', $r->getContent()), $cabeceras, $r->getStatusCode()];
    }

    public function test_la_ruta_es_publica_y_tiene_nombre(): void
    {
        $this->assertSame('/verificar/ABC', route('verificacion.publica', 'ABC', false));
        $this->assertSame(['GET', 'HEAD'], app('router')->getRoutes()->getByName('verificacion.publica')->methods());
        $this->assertNotContains('auth', app('router')->getRoutes()->getByName('verificacion.publica')->gatherMiddleware());
    }

    public function test_emision_vigente_muestra_solo_los_datos_publicos(): void
    {
        $e = $this->emitida();

        $r = $this->publica($e->codigo)->assertOk();

        $r->assertSee('Credencial válida')->assertSee('PERSONA NÚMERO 1')->assertSee('CONGRESO DE FINANZAS')
            ->assertSee('17 de septiembre de 2026')->assertSee('30 horas')->assertSee('F&amp;C Consultores S.A.S.', false)
            ->assertSee($e->codigo)->assertSee($e->emitido_at->copy()->timezone('America/Bogota')->locale('es')->translatedFormat('j \d\e F \d\e Y'));
        $html = $r->getContent();
        $this->assertStringNotContainsString('20.000.001', $html, 'El documento no se muestra ni completo ni enmascarado');
        $this->assertStringNotContainsString('20000001', $html);
        $this->assertStringNotContainsString('0001', $html);
        $this->assertStringNotContainsString('credential-flow/', $html, 'Sin rutas de archivo');
        $this->assertStringNotContainsString('participante', strtolower($html));
        $this->assertStringNotContainsString('lote_id', $html);
        $this->assertStringNotContainsString('Lote ', $html, 'Ni el nombre del lote');
        $this->assertStringNotContainsString('Plantilla ', $html, 'Ni el nombre de la plantilla');
        $this->assertStringNotContainsString('erik@', $html, 'Ni quién emitió');
        $this->assertStringNotContainsString('Versión', $html);
        $this->assertStringNotContainsString('Descargar', $html, 'No hay descarga pública del PDF');
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_emision_revocada_no_muestra_nombre_documento_ni_motivo(): void
    {
        $e = $this->emitida();
        $this->revocar($e);

        $r = $this->publica($e->codigo)->assertOk();

        $r->assertSee('Credencial revocada')->assertSee('CONGRESO DE FINANZAS')->assertSee('17 de septiembre de 2026')->assertSee($e->codigo)
            ->assertSee('Esta credencial ya no es válida. Si recibió una versión más reciente, verifique esa.');
        $html = $r->getContent();
        $this->assertStringNotContainsString('PERSONA', $html, 'La revocada no muestra el nombre');
        $this->assertStringNotContainsString('20.000.001', $html);
        $this->assertStringNotContainsString(self::MOTIVO, $html);
        $this->assertStringNotContainsString('XYZ', $html);
        $this->assertStringNotContainsString('30 horas', $html);
        $this->assertStringNotContainsString('Credencial válida', $html);
        $this->assertStringNotContainsString('href=', $html, 'No enlaza a ninguna reemisión');
    }

    public function test_una_emision_reemplazada_se_ve_como_cualquier_revocada_sin_enlazar_la_nueva(): void
    {
        $e = $this->emitida();
        $nueva = Emision::findOrFail($this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $e), ['motivo' => 'Corrección de datos'])->assertCreated()->json('emision.id'));

        $vieja = $this->publica($e->codigo)->assertOk();

        $vieja->assertSee('Credencial revocada');
        $this->assertStringNotContainsString($nueva->codigo, $vieja->getContent());
        $this->assertStringNotContainsString('reemplaz', strtolower($vieja->getContent()));
        $this->publica($nueva->codigo)->assertOk()->assertSee('Credencial válida');
    }

    public function test_inexistente_y_formato_invalido_son_identicos_y_no_repiten_el_codigo(): void
    {
        $this->emitida();
        $inexistente = $this->publica('ZZZZZZZZZZZZZZZZZZZZ');
        $corto = $this->publica('ABC');
        $largo = $this->publica(str_repeat('A', 21));
        $raro = $this->publica('ab-cd_ef');

        $inexistente->assertNotFound()->assertSee('Credencial no encontrada');
        $base = $this->normalizar($inexistente);
        foreach ([$corto, $largo, $raro] as $r) {
            $this->assertSame($base, $this->normalizar($r), 'Misma vista, mismo estado y mismas cabeceras');
        }
        $this->assertStringNotContainsString('ZZZZZZ', $inexistente->getContent());
        $this->assertStringNotContainsString('ABC', $corto->getContent());
        $this->assertStringNotContainsString('ab-cd', $raro->getContent());
    }

    public function test_normaliza_mayusculas_y_espacios_pero_no_aplica_decode_flexible(): void
    {
        $e = $this->emitida();

        $this->publica(strtolower($e->codigo))->assertOk()->assertSee('Credencial válida');
        $this->publica('%20'.$e->codigo.'%20')->assertOk();

        // Longitud incorrecta
        $this->publica(substr($e->codigo, 0, 19))->assertNotFound();
        $this->publica($e->codigo.'A')->assertNotFound();
        // Letras que NO están en el alfabeto (I, L, O, U) no se reinterpretan como 1, 1, 0 y V.
        foreach (['I', 'L', 'O', 'U', 'i', 'o'] as $letra) {
            $this->publica(substr($e->codigo, 0, 19).$letra)->assertNotFound();
        }
    }

    public function test_no_hace_falta_iniciar_sesion_y_un_admin_tampoco_cambia_el_resultado(): void
    {
        $e = $this->emitida();

        $this->app['auth']->forgetGuards();
        $this->get('/verificar/'.$e->codigo)->assertOk();
        $this->actingAs($this->admin())->get('/verificar/'.$e->codigo)->assertOk()->assertSee('Credencial válida');
    }

    public function test_la_verificacion_muestra_lo_emitido_aunque_cambien_los_datos_vivos(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->firstOrFail();
        $this->emitirPor($lote, $p)->assertCreated();
        $e = Emision::firstOrFail();
        $antes = $this->publica($e->codigo)->getContent();

        $p->update(['nombre_completo' => 'NOMBRE CAMBIADO', 'documento' => 'C.C. 999']);
        $lote->update(['nombre' => 'Lote renombrado', 'datos_comunes' => ['evento' => 'OTRO EVENTO', 'fecha' => 'otra fecha', 'intensidad_horaria' => '1 hora']]);
        $lote->plantilla->update(['nombre' => 'Plantilla renombrada']);

        $despues = $this->publica($e->codigo);
        $despues->assertOk()->assertSee('PERSONA NÚMERO 1')->assertSee('CONGRESO DE FINANZAS');
        $this->assertStringNotContainsString('NOMBRE CAMBIADO', $despues->getContent());
        $this->assertStringNotContainsString('OTRO EVENTO', $despues->getContent());
        $this->assertSame(preg_replace('/nonce="[^"]+"/', '', $antes), preg_replace('/nonce="[^"]+"/', '', $despues->getContent()));
    }

    public function test_solo_consulta_cf_emisiones(): void
    {
        $e = $this->emitida();
        $tablas = [];
        DB::listen(function ($q) use (&$tablas) {
            foreach (['cf_participantes', 'cf_lotes', 'cf_plantillas', 'usuarios'] as $t) {
                if (str_contains($q->sql, $t)) {
                    $tablas[] = $t;
                }
            }
        });

        $this->publica($e->codigo)->assertOk();

        $this->assertSame([], $tablas, 'La verificación pública no lee datos vivos de participante, lote ni plantilla');
    }

    public function test_cabeceras_noindex_sin_cache_y_csp_estricta(): void
    {
        $e = $this->emitida();

        foreach ([$this->publica($e->codigo), $this->publica('ZZZZZZZZZZZZZZZZZZZZ')] as $r) {
            $this->assertSame('noindex, nofollow, noarchive, nosnippet', $r->headers->get('X-Robots-Tag'));
            $cc = (string) $r->headers->get('Cache-Control');
            foreach (['no-store', 'private', 'max-age=0'] as $d) {
                $this->assertStringContainsString($d, $cc);
            }
            $this->assertStringNotContainsString('public', $cc);
            $this->assertSame('no-cache', $r->headers->get('Pragma'));
            $this->assertSame('no-referrer', $r->headers->get('Referrer-Policy'));
            $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
            $this->assertSame('DENY', $r->headers->get('X-Frame-Options'));
            $this->assertStringContainsString('name="robots" content="noindex, nofollow, noarchive, nosnippet"', $r->getContent());
            $this->assertStringContainsString('lang="es"', $r->getContent());

            $csp = (string) $r->headers->get('Content-Security-Policy');
            $this->assertMatchesRegularExpression("/^default-src 'none'; style-src 'self' 'nonce-[A-Za-z0-9+\/=]+'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'$/", $csp);
            preg_match("/'nonce-([^']+)'/", $csp, $m);
            $this->assertStringContainsString('<style nonce="'.$m[1].'">', $r->getContent(), 'El nonce de la CSP es el de la vista');
            $this->assertStringNotContainsString('style="', $r->getContent(), 'Sin estilos en atributo (la CSP no los permite)');
            $this->assertDoesNotMatchRegularExpression('#https?://#i', $r->getContent(), 'Sin recursos externos');
        }
        $this->assertNotSame($this->publica($e->codigo)->headers->get('Content-Security-Policy'), $this->publica($e->codigo)->headers->get('Content-Security-Policy'), 'Un nonce distinto por respuesta');
    }

    public function test_no_crea_sesion_ni_cookies(): void
    {
        $e = $this->emitida();

        foreach ([$e->codigo, 'ZZZZZZZZZZZZZZZZZZZZ', 'x'] as $codigo) {
            $r = $this->publica($codigo);
            $this->assertSame([], $r->headers->getCookies(), 'La respuesta no debe emitir ninguna cookie');
            $this->assertFalse($r->headers->has('Set-Cookie'));
        }
        $this->assertFalse(app('session.store')->isStarted(), 'La sesión no se inició en la petición pública');
    }

    public function test_head_funciona(): void
    {
        $e = $this->emitida();

        $this->call('HEAD', '/verificar/'.$e->codigo)->assertOk();
    }

    public function test_limite_general_por_ip_con_429_amable_y_retry_after(): void
    {
        config(['credential_flow.verificacion.limite_por_minuto' => 3]);
        $e = $this->emitida();
        RateLimiter::clear('cf-verificacion:127.0.0.1');
        Log::spy();

        for ($i = 0; $i < 3; $i++) {
            $this->publica($e->codigo)->assertOk();
        }
        $r = $this->publica($e->codigo);

        $r->assertStatus(429)->assertSee('Demasiadas consultas');
        $this->assertGreaterThanOrEqual(1, (int) $r->headers->get('Retry-After'));
        $this->assertSame('noindex, nofollow, noarchive, nosnippet', $r->headers->get('X-Robots-Tag'), 'La 429 también lleva las cabeceras');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $r->headers->get('Content-Security-Policy'));
        $this->assertSame([], $r->headers->getCookies());
        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $ctx) use ($e) {
            return str_contains($mensaje, 'limitada') && array_keys($ctx) === ['ip', 'ua'] && $ctx['ip'] === '127.0.0.0' && ! str_contains(json_encode($ctx), $e->codigo);
        })->atLeast()->once();
    }

    public function test_limite_de_consultas_fallidas_por_ip(): void
    {
        $e = $this->emitida();
        $max = (int) config('credential_flow.verificacion.limite_fallos_por_minuto');
        $this->assertSame(15, $max);

        for ($i = 0; $i < $max; $i++) {
            $this->publica('ZZZZZZZZZZZZZZZZZZZ'.chr(65 + $i % 8))->assertNotFound();
        }
        // Superado el límite de fallos, la respuesta es 429 (incluso con un código válido: respuesta uniforme).
        $this->publica('ZZZZZZZZZZZZZZZZZZZZ')->assertStatus(429);
        $this->publica($e->codigo)->assertStatus(429);
    }

    public function test_las_consultas_exitosas_no_cuentan_como_fallos(): void
    {
        $e = $this->emitida();

        for ($i = 0; $i < 40; $i++) {
            $this->publica($e->codigo)->assertOk();
        }
    }

    public function test_no_se_registra_nada_por_consultas_normales(): void
    {
        $e = $this->emitida();
        Log::spy();

        $this->publica($e->codigo)->assertOk();
        $this->publica('ZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');
    }

    public function test_url_base_sale_de_la_configuracion_y_no_de_la_peticion(): void
    {
        config(['app.url' => 'https://app.ejemplo.test/', 'credential_flow.verificacion.base_url' => null]);
        $this->assertSame('https://app.ejemplo.test/verificar/ABC', UrlVerificacion::para('ABC'));

        config(['credential_flow.verificacion.base_url' => 'https://verificar.ejemplo.test//']);
        $this->assertSame('https://verificar.ejemplo.test/verificar/ABC', UrlVerificacion::para('ABC'));
        $this->assertSame('https://verificar.ejemplo.test/verificar/'.UrlVerificacion::CODIGO_EJEMPLO, UrlVerificacion::ejemplo());
        $this->assertTrue(CodigoEmision::valido(UrlVerificacion::CODIGO_EJEMPLO), 'El código de ejemplo cumple el alfabeto');
    }
}
