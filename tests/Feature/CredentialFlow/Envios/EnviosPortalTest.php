<?php

namespace Tests\Feature\CredentialFlow\Envios;

use App\Mail\CodigoAccesoMail;
use App\Models\CredentialFlow\Envio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

/** Integración del servicio de envíos con el portal OTP de la Fase 7: nada cambia para quien consulta; todo queda auditado. */
class EnviosPortalTest extends EnviosTestCase
{
    private function falloPermanente(): TransportException
    {
        return new TransportException('Expected response code "250" but got code "550", with message "550 ana.uno@example.test no existe".');
    }

    private function codigoDe(TransportePrueba $t): string
    {
        preg_match('/\b(\d{6})\b/', $t->enviados[0]->getTextBody(), $m);

        return $m[1];
    }

    public function test_una_solicitud_valida_crea_un_desafio_un_envio_y_un_correo_aceptado(): void
    {
        $t = $this->usarTransporte();

        $this->solicitar('1.000.001', self::CORREO)->assertRedirect(route('portal.codigo'));

        $this->assertSame(1, DB::table('cf_accesos_otp')->count());
        $e = Envio::firstOrFail();
        $this->assertSame([Envio::ACEPTADO, 1, 'a***@example.test'], [$e->estado, $e->intentos, $e->destinatario_mascara]);
        $this->assertSame((int) DB::table('cf_accesos_otp')->value('id'), (int) $e->origen_id);
        $this->assertNotNull(DB::table('cf_accesos_otp')->value('enviado_at'));
        $this->assertCount(1, $t->enviados);
    }

    public function test_el_recorrido_completo_funciona_con_el_codigo_del_correo(): void
    {
        $t = $this->usarTransporte();
        $this->solicitar('1000001', self::CORREO);

        $this->validarCodigo($this->codigoDe($t))->assertRedirect(route('portal.panel'));

        $this->get(route('portal.panel'))->assertOk();
    }

    public function test_si_no_hay_coincidencia_no_se_crea_ni_desafio_ni_envio_ni_correo(): void
    {
        $t = $this->usarTransporte();

        foreach ([['9999999', 'nadie@example.test'], ['1000001', 'otra@example.test'], ['ABC123', 'diego@example.test']] as [$doc, $correo]) {
            $this->solicitar($doc, $correo)->assertRedirect(route('portal.codigo'));
        }

        $this->assertSame([0, 0, 0], [DB::table('cf_accesos_otp')->count(), Envio::count(), $t->llamadas]);
    }

    public function test_la_respuesta_publica_es_identica_si_el_correo_se_envia_si_falla_o_si_no_hay_coincidencia(): void
    {
        config(['credential_flow.portal.limite_solicitud_por_minuto' => 100]);
        $paginas = [];
        $casos = [
            'acepta' => [[], '1000001', self::CORREO],
            'falla (permanente)' => [[$this->falloPermanente()], '1000001', self::CORREO2],
            'falla (temporal x2)' => [[new TransportException('Connection could not be established'), new TransportException('Connection could not be established')], '2000001', 'gina@example.test'],
            'sin coincidencia' => [[], '9999999', 'nadie@example.test'],
        ];
        foreach ($casos as [$secuencia, $doc, $correo]) {
            $this->usarTransporte($secuencia);
            $this->post(route('portal.salir'));
            $r = $this->solicitar($doc, $correo);
            $paginas[] = [$r->getStatusCode(), $r->headers->get('Location'), $this->normalizar($this->get(route('portal.codigo')))[0]];
        }

        $this->assertCount(1, array_unique(array_map('serialize', $paginas)), 'Nada permite distinguir un envío correcto de uno fallido ni de la falta de coincidencia');
    }

    public function test_un_fallo_de_envio_queda_registrado_pero_no_se_ve_en_la_respuesta(): void
    {
        $this->usarTransporte([$this->falloPermanente()]);

        $r = $this->solicitar('1000001', self::CORREO);

        $r->assertRedirect(route('portal.codigo'))->assertSessionHas('aviso', 'Si los datos coinciden con nuestros registros, te enviaremos un código al correo indicado.');
        $e = Envio::firstOrFail();
        $this->assertSame([Envio::FALLIDO_PERMANENTE, 'SMTP_550'], [$e->estado, $e->error_codigo]);
        $this->assertNull(DB::table('cf_accesos_otp')->value('enviado_at'));
        // Texto visible (sin las reglas de estilo, que incluyen clases como «.error»).
        $html = strip_tags(preg_replace('#<style.*?</style>#s', '', $this->get(route('portal.codigo'))->getContent()));
        foreach (['SMTP', '550', 'falló', 'No se pudo', 'servidor de correo', self::CORREO] as $pista) {
            $this->assertStringNotContainsStringIgnoringCase($pista, $html);
        }
    }

    public function test_los_limites_de_la_fase_7_siguen_igual_aunque_el_envio_haya_fallado(): void
    {
        $this->usarTransporte([$this->falloPermanente()]);
        $this->solicitar('1000001', self::CORREO);

        // 30 s después: sigue bloqueado el reenvío (60 s), aunque el primero no salió.
        $this->travel(30)->seconds();
        $this->post(route('portal.reenviar'));
        $this->assertSame([1, 1], [DB::table('cf_accesos_otp')->count(), Envio::count()]);

        // Pasados 60 s, la persona puede pedir otro código: nuevo desafío y nuevo envío.
        $this->travel(31)->seconds();
        $this->post(route('portal.reenviar'));
        $this->assertSame([2, 2], [DB::table('cf_accesos_otp')->count(), Envio::count()]);
    }

    public function test_maximo_tres_otp_por_hora_se_cumple_con_su_envio_por_desafio(): void
    {
        $t = $this->usarTransporte();
        foreach (range(1, 5) as $_) {
            $this->travel(61)->seconds();
            $this->solicitar('1000001', self::CORREO);
        }

        $this->assertSame([3, 3, 3], [DB::table('cf_accesos_otp')->count(), Envio::count(), count($t->enviados)]);
    }

    public function test_un_otp_nuevo_invalida_el_anterior_y_cada_desafio_tiene_su_propio_envio(): void
    {
        $t = $this->usarTransporte();
        $this->solicitar('1000001', self::CORREO);
        $viejo = $this->codigoDe($t);
        $this->travel(61)->seconds();
        $this->solicitar('1000001', self::CORREO);

        $this->assertSame(2, Envio::count());
        $this->assertSame(2, DB::table('cf_envios')->distinct()->count('clave_idempotencia'));
        $this->assertSame(1, DB::table('cf_accesos_otp')->whereNotNull('invalidado_at')->count());
        $nuevo = preg_match('/\b(\d{6})\b/', $t->enviados[1]->getTextBody(), $m) ? $m[1] : '';
        $this->validarCodigo($viejo === $nuevo ? '000000' : $viejo)->assertSessionHas('error');
        $this->validarCodigo($nuevo)->assertRedirect(route('portal.panel'));
    }

    public function test_reintento_inmediato_dentro_del_flujo_del_portal(): void
    {
        $t = $this->usarTransporte([new TransportException('Expected response code "250" but got code "451", with message "451 x"')]);

        $this->solicitar('1000001', self::CORREO);

        $e = Envio::firstOrFail();
        $this->assertSame([Envio::ACEPTADO, 2], [$e->estado, $e->intentos]);
        $this->assertSame(2, $t->llamadas);
        $this->assertCount(1, $t->enviados, 'Un solo correo llega al destinatario');
    }

    public function test_con_los_envios_desactivados_el_portal_responde_igual_y_no_sale_nada(): void
    {
        $t = $this->usarTransporte();
        config(['credential_flow.correo.habilitado' => false]);

        $this->solicitar('1000001', self::CORREO)->assertRedirect(route('portal.codigo'))->assertSessionHas('aviso');

        $this->assertSame(0, $t->llamadas);
        $this->assertSame('ENVIO_DESACTIVADO', Envio::firstOrFail()->error_codigo);
    }

    public function test_el_envio_se_hace_despues_de_responder_con_defer(): void
    {
        $fuente = file_get_contents(app_path('Support/CredentialFlow/Portal/ServicioOtp.php'));

        $this->assertStringContainsString('defer(fn () => $this->envios->enviarOtp(', $fuente);
        $this->assertStringNotContainsString('Mail::', $fuente, 'El portal ya no envía directamente: lo hace el servicio de envíos');
    }

    public function test_ningun_registro_guarda_el_codigo_del_correo_ni_datos_personales_en_un_flujo_completo(): void
    {
        $t = $this->usarTransporte();
        $this->solicitar('1.000.001', self::CORREO);
        $codigo = $this->codigoDe($t);
        $respuestas = $this->get(route('portal.codigo'))->getContent().$this->validarCodigo($codigo)->getContent();

        $todo = $this->volcadoEnvios().$this->volcadoRegistros().json_encode(DB::table('cf_accesos_otp')->get()->all()).$respuestas;
        foreach ([$codigo, self::CORREO, self::NOMBRE, '1000001'] as $dato) {
            $this->assertStringNotContainsString($dato, $todo, "Se filtra «{$dato}»");
        }
    }

    public function test_con_correo_falso_de_laravel_el_flujo_de_la_fase_7_sigue_funcionando(): void
    {
        Mail::fake();

        $this->solicitar('1000001', self::CORREO);

        Mail::assertSent(CodigoAccesoMail::class, 1);
        $this->assertSame(Envio::ACEPTADO, Envio::firstOrFail()->estado);
    }
}
