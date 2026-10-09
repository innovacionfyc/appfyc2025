<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\Emision;
use App\Support\CredentialFlow\Verificacion\UrlVerificacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\CredentialFlow\EmisionesTestCase;
use Tests\Feature\CredentialFlow\Historico\Concerns\MigraHistoricoSintetico;
use Tests\Feature\CredentialFlow\StagingEv\FixturesStagingEv;

/** Verificación pública de códigos LEGADO (Fase 6): privacidad, resolución, casos no habilitados, cabeceras y límites. Datos sintéticos. */
class VerificacionLegadoTest extends EmisionesTestCase
{
    use FixturesStagingEv, MigraHistoricoSintetico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararHistorico();
        $d = $this->datos();
        foreach ([1 => 5237, 3 => 5555, 4 => 5600, 7 => 5300, 8 => 5300, 9 => 5700, 10 => 5700, 11 => 12045, 13 => 5800] as $id => $codigo) {
            $d = $this->conCambio($d, 'participante', $id, ['num_verificacion' => $codigo]);
        }
        $this->migrarSintetico($d);
        foreach (['min', 'hora'] as $k) {
            RateLimiter::clear("cf-verificacion-legado:$k:127.0.0.1");
        }
        RateLimiter::clear('cf-verificacion-fallos:127.0.0.1');
    }

    private function publica(string $codigo): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->get('/verificar/'.$codigo);
    }

    private function normalizar(TestResponse $r): array
    {
        $h = $r->headers->all();
        unset($h['date'], $h['x-ratelimit-remaining'], $h['x-ratelimit-limit']);
        $h['content-security-policy'] = preg_replace("/'nonce-[^']+'/", "'nonce-N'", $h['content-security-policy']);

        return [preg_replace('/nonce="[^"]+"/', 'nonce="N"', $r->getContent()), $h, $r->getStatusCode()];
    }

    private function emisionModerna(): Emision
    {
        $lote = $this->loteCon(1);
        $this->emitirPor($lote, $lote->participantes()->firstOrFail())->assertCreated();
        $this->app['auth']->forgetGuards();

        return Emision::firstOrFail();
    }

    public function test_codigo_legado_valido_muestra_solo_evento_anio_estado_y_origen(): void
    {
        $r = $this->publica('5237')->assertOk();

        $r->assertSee('Certificado histórico válido')->assertSee('Curso Alfa 2024')->assertSee('2024')->assertSee('Válido')
            ->assertSee('Certificado histórico (sistema anterior)')->assertSee('5237');
        $r->assertDontSee('Descargar')->assertDontSee('.pdf');
    }

    public function test_privacidad_no_se_filtra_ningun_dato_personal_ni_tecnico(): void
    {
        foreach (['5237', '5300', '5600', '5700', '5555', '12045'] as $codigo) {
            $html = $this->publica($codigo)->getContent();
            foreach ([self::NOMBRE, self::DOCUMENTO, self::CORREO, 'GINA', 'HUGO', 'DIEGO', 'gina@', 'ABC123', '2000001', 'grupo', 'corrida', 'old_id', 'snapshot', 'advertencia', 'canonico', 'duplicad', 'conflict', 'plantilla', 'credential-flow/'] as $prohibido) {
                $this->assertStringNotContainsStringIgnoringCase($prohibido, $html, "El código $codigo expone {$prohibido}");
            }
        }
    }

    public function test_los_codigos_de_4_y_5_cifras_funcionan(): void
    {
        $this->publica('5237')->assertOk()->assertSee('Certificado histórico válido');
        // 5 cifras: participante 11 (evento 2, pendiente) → existe, está en revisión.
        $this->publica('12045')->assertOk()->assertSee('Este certificado histórico se encuentra en revisión.');
    }

    public function test_codigo_inexistente_es_404_uniforme_sin_eco(): void
    {
        $r = $this->publica('9999')->assertNotFound();

        $r->assertSee('Credencial no encontrada');
        $this->assertStringNotContainsString('9999', $r->getContent());
    }

    public function test_la_busqueda_es_exacta_nunca_parcial_y_solo_acepta_4_o_5_digitos(): void
    {
        foreach (['523', '52370', '5238', '052370', '5237a', '5%20237', '0005237'] as $codigo) {
            $this->publica($codigo)->assertNotFound();
        }
    }

    public function test_un_codigo_legado_nunca_se_acepta_como_moderno_ni_al_reves(): void
    {
        // Un código moderno (20 caracteres) con forma válida pero inexistente: 404 y no toca el contador legado.
        $this->publica('ABCDEFGHJKMNPQRSTVWX')->assertNotFound();
        $this->assertSame(0, RateLimiter::attempts('cf-verificacion-legado:min:127.0.0.1'));
        // Y un código legado no consulta cf_emisiones (salvo para enlazar un reemplazo, que aquí no existe).
        $q = $this->sentencias(fn () => $this->publica('5237'));
        $this->assertEmpty(array_filter($q, fn ($s) => str_contains($s, 'cf_emisiones')));
    }

    public function test_el_codigo_moderno_sigue_funcionando_con_el_historico_cargado(): void
    {
        $e = $this->emisionModerna();

        $this->publica($e->codigo)->assertOk()->assertSee('Credencial válida')->assertSee('PERSONA NÚMERO 1');
        $this->publica(strtolower($e->codigo))->assertOk()->assertSee('Credencial válida');
    }

    public function test_el_mismo_codigo_en_varias_filas_del_mismo_par_es_una_sola_verificacion(): void
    {
        $this->assertSame(2, DB::table('cf_certificados_legado')->where('codigo_legado', '5300')->count());

        $r = $this->publica('5300')->assertOk();

        $r->assertSee('Certificado histórico válido');
        $this->assertSame(1, substr_count($r->getContent(), 'data-dato="codigo"'));
        $this->assertSame(1, substr_count($r->getContent(), 'data-dato="evento"'));
    }

    public function test_colision_entre_pares_distintos_no_revela_nada_y_deja_error_tecnico_sin_datos_personales(): void
    {
        // Mismo código 5300 (evento 1, doc 2000001) en otro par: el certificado del participante 1 (otro documento).
        DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->update(['codigo_legado' => '5300']);
        Log::spy();

        $r = $this->publica('5300');
        $inexistente = $this->publica('9998');

        $r->assertNotFound()->assertSee('Credencial no encontrada');
        $this->assertSame($this->normalizar($inexistente), $this->normalizar($r), 'Idéntica a la de un código inexistente');
        foreach ([self::NOMBRE, self::DOCUMENTO, 'Curso Alfa', 'GINA'] as $s) {
            $this->assertStringNotContainsString($s, $r->getContent());
        }
        Log::shouldHaveReceived('error')->withArgs(function ($m, $ctx) {
            $json = json_encode($ctx);

            return str_contains($m, 'código legado compartido') && $ctx['pares'] === 2
                && ! str_contains($json, '5300') && ! str_contains($json, '127.0.0.1') && ! str_contains($json, self::DOCUMENTO) && ! str_contains($json, 'GINA');
        })->once();
    }

    /** @return array<string,array{0:string}> */
    public static function enRevision(): array
    {
        return [
            'revision_documento' => ['5600'],
            'pendiente_plantilla' => ['5555'],
            'pendiente_conciliacion (conflictivo)' => ['5700'],
        ];
    }

    #[DataProvider('enRevision')]
    public function test_los_casos_no_habilitados_responden_en_revision_sin_motivo_ni_pdf(string $codigo): void
    {
        $r = $this->publica($codigo)->assertOk();

        $r->assertSee('Este certificado histórico se encuentra en revisión.')->assertDontSee('Certificado histórico válido');
        $this->assertStringNotContainsString('Curso ', $r->getContent(), 'Ni el evento: solo se confirma que está en revisión');
        $this->assertStringNotContainsString('.pdf', $r->getContent());
        $this->assertStringNotContainsString('Descargar', $r->getContent());
    }

    public function test_el_duplicado_identico_se_comporta_como_un_unico_certificado(): void
    {
        $canonico = $this->idCert(7);
        $copia = $this->idCert(8);
        $this->assertSame('duplicado_consolidado', DB::table('cf_certificados_legado')->where('id', $copia)->value('conciliacion_estado'));
        // Si el canónico se revoca, el código (que comparten ambos) responde revocado, una sola vez.
        DB::table('cf_certificados_legado')->where('id', $canonico)->update(['estado' => 'revocado']);

        $this->publica('5300')->assertOk()->assertSee('Certificado revocado');
    }

    public function test_certificado_revocado_no_muestra_datos_personales(): void
    {
        DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->update(['estado' => 'revocado']);

        $r = $this->publica('5237')->assertOk();

        $r->assertSee('Certificado revocado')->assertSee('Curso Alfa 2024')->assertDontSee('Certificado histórico válido');
        $this->assertStringNotContainsString(self::NOMBRE, $r->getContent());
        $this->assertStringNotContainsString(self::DOCUMENTO, $r->getContent());
    }

    public function test_reemplazado_por_una_emision_moderna_enlaza_por_codigo_publico(): void
    {
        $e = $this->emisionModerna();
        DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->update(['estado' => 'reemplazado', 'reemplazado_por_emision_id' => $e->id]);

        $r = $this->publica('5237')->assertOk();

        $r->assertSee('Este certificado histórico fue reemplazado por una versión posterior.');
        $this->assertStringContainsString('href="'.UrlVerificacion::para($e->codigo).'"', $r->getContent());
        $this->assertStringNotContainsString('emision_id', $r->getContent());
        $this->publica($e->codigo)->assertOk();
    }

    public function test_reemplazado_sin_emision_asociada_no_ofrece_enlace(): void
    {
        DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->update(['estado' => 'reemplazado']);

        // Estado inconsistente (reemplazado sin emisión): nada de enlaces ni detalles; «en revisión» (10B-2B-2C).
        $this->publica('5237')->assertOk()->assertSee('en revisión')->assertDontSee('version-posterior')->assertDontSee('reemplazado');
    }

    public function test_cabeceras_estrictas_y_sin_cookies_igual_que_la_verificacion_moderna(): void
    {
        foreach (['5237' => 200, '5600' => 200, '9999' => 404] as $codigo => $estado) {
            $r = $this->publica((string) $codigo);
            $r->assertStatus($estado);
            $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
            $this->assertStringContainsString('noindex', (string) $r->headers->get('X-Robots-Tag'));
            $this->assertNotNull($r->headers->get('Referrer-Policy'));
            $this->assertNotNull($r->headers->get('Content-Security-Policy'));
            $this->assertSame([], $r->headers->getCookies(), 'Sin cookies');
        }
        $this->assertSame($this->normalizar($this->publica('ABCDEFGHJKMNPQRSTVWX')), $this->normalizar($this->publica('9999')));
    }

    public function test_limite_por_minuto_responde_429_con_retry_after_y_las_mismas_cabeceras(): void
    {
        config(['credential_flow.verificacion.limite_legado_por_minuto' => 3]);
        for ($i = 0; $i < 3; $i++) {
            $this->publica('5237')->assertOk();
        }

        $r = $this->publica('5237');

        $r->assertStatus(429)->assertSee('Demasiadas consultas');
        $this->assertGreaterThanOrEqual(1, (int) $r->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('5237', $r->getContent());
    }

    public function test_limite_por_hora_tambien_aplica(): void
    {
        config(['credential_flow.verificacion.limite_legado_por_hora' => 2]);
        $this->publica('5237')->assertOk();
        $this->publica('5237')->assertOk();

        $this->publica('5237')->assertStatus(429);
    }

    public function test_los_fallos_se_cuentan_en_el_limite_de_fallos_general(): void
    {
        config(['credential_flow.verificacion.limite_fallos_por_minuto' => 2]);
        $this->publica('9991')->assertNotFound();
        $this->publica('9992')->assertNotFound();

        $this->publica('5237')->assertStatus(429);
    }

    public function test_el_limite_legado_es_independiente_del_moderno(): void
    {
        config(['credential_flow.verificacion.limite_legado_por_minuto' => 1]);
        $this->publica('5237')->assertOk();
        $this->publica('5237')->assertStatus(429);
        $this->assertSame(1, RateLimiter::attempts('cf-verificacion-legado:min:127.0.0.1'));

        RateLimiter::clear('cf-verificacion-legado:min:127.0.0.1');
        $this->publica('ABCDEFGHJKMNPQRSTVWX')->assertNotFound();
        $this->assertSame(0, RateLimiter::attempts('cf-verificacion-legado:min:127.0.0.1'));
    }

    public function test_una_consulta_normal_no_registra_logs(): void
    {
        Log::spy();

        $this->publica('5237')->assertOk();
        $this->publica('9999')->assertNotFound();

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');
    }
}
