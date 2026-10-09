<?php

namespace Tests\Feature\CredentialFlow\Envios;

use App\Mail\CodigoAccesoMail;
use App\Models\CredentialFlow\Envio;
use App\Support\CredentialFlow\Envios\ClasificadorErrorEnvio;
use App\Support\CredentialFlow\Envios\ServicioEnvios;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/** Servicio de envíos (Fase 9): estados, intentos, reintento inmediato, idempotencia, límites y privacidad. Sin SMTP real ni correos externos. */
class EnviosServicioTest extends EnviosTestCase
{
    private function transitorio(string $codigo = '451'): TransportException
    {
        return new TransportException('Expected response code "250" but got code "'.$codigo.'", with message "'.$codigo.' ana.uno@example.test temporalmente no disponible".');
    }

    private function permanente(): TransportException
    {
        return new TransportException('Expected response code "250" but got code "550", with message "550 5.1.1 ana.uno@example.test no existe".');
    }

    private function enviar(int $otp, string $correo = self::CORREO): ?Envio
    {
        return $this->servicio()->enviarOtp($otp, $correo, self::CODIGO);
    }

    // ── Envío aceptado ────────────────────────────────────────────────────────

    public function test_envio_exitoso_queda_aceptado_con_su_intento_y_la_referencia_del_servidor(): void
    {
        $t = $this->usarTransporte();
        $otp = $this->crearDesafio();

        $e = $this->enviar($otp);

        $this->assertSame(Envio::ACEPTADO, $e->estado);
        $this->assertSame([1, 2, 'otp_acceso', 1, 'otp'], [$e->intentos, $e->max_intentos, $e->plantilla, $e->plantilla_version, $e->origen_tipo]);
        $this->assertSame($otp, (int) $e->origen_id);
        $this->assertNotNull($e->aceptado_at);
        $this->assertSame('QID12345', $e->proveedor_referencia);
        $this->assertNull($e->error_codigo);
        $i = DB::table('cf_envios_intentos')->where('envio_id', $e->id)->get();
        $this->assertCount(1, $i);
        $this->assertSame([1, 'aceptado', 'prueba', 'QID12345', null], [(int) $i[0]->numero, $i[0]->resultado, $i[0]->transporte, $i[0]->proveedor_referencia, $i[0]->error_codigo]);
        $this->assertCount(1, $t->enviados);
    }

    public function test_el_correo_enviado_tiene_destinatario_asunto_codigo_html_y_texto_sin_datos_de_mas(): void
    {
        config(['credential_flow.correo.remitente' => ['direccion' => 'certificados@example.test', 'nombre' => 'F&C Consultores'], 'credential_flow.correo.reply_to' => ['direccion' => 'soporte@example.test', 'nombre' => 'Soporte']]);
        $t = $this->usarTransporte();
        $this->enviar($this->crearDesafio());

        /** @var Email $m */
        $m = $t->enviados[0];
        $this->assertSame(self::CORREO, $m->getTo()[0]->getAddress());
        $this->assertSame('Código para consultar tus certificados', $m->getSubject());
        $this->assertStringContainsString(self::CODIGO, $m->getHtmlBody());
        $this->assertStringContainsString(self::CODIGO, $m->getTextBody());
        $this->assertStringContainsString('10 minutos', $m->getTextBody());
        $this->assertSame('certificados@example.test', $m->getFrom()[0]->getAddress());
        $this->assertSame('soporte@example.test', $m->getReplyTo()[0]->getAddress());
        $this->assertSame([], $m->getAttachments());
        foreach ([self::NOMBRE, self::DOCUMENTO, 'Curso '] as $dato) {
            $this->assertStringNotContainsString($dato, $m->getHtmlBody().$m->getTextBody());
        }
    }

    public function test_con_el_correo_falso_de_laravel_tambien_queda_aceptado(): void
    {
        // PortalTestCase usa Mail::fake(): el transporte «acepta» siempre.
        $e = $this->enviar($this->crearDesafio());

        $this->assertSame(Envio::ACEPTADO, $e->estado);
        Mail::assertSent(CodigoAccesoMail::class, 1);
    }

    public function test_enviado_at_del_desafio_solo_se_marca_cuando_el_transporte_acepta(): void
    {
        $t = $this->usarTransporte();
        $otp = $this->crearDesafio();
        $vistoDurante = null;
        $t->alEnviar = function () use ($otp, &$vistoDurante) {
            $vistoDurante = [DB::table('cf_envios')->value('estado'), DB::table('cf_accesos_otp')->where('id', $otp)->value('enviado_at')];
        };

        $this->enviar($otp);

        $this->assertSame([Envio::PROCESANDO, null], $vistoDurante, 'Mientras se envía: «procesando» y sin fecha de envío (no está confirmado)');
        $this->assertNotNull(DB::table('cf_accesos_otp')->where('id', $otp)->value('enviado_at'));
        $this->assertSame(DB::table('cf_envios')->value('aceptado_at'), DB::table('cf_accesos_otp')->where('id', $otp)->value('enviado_at'));
    }

    public function test_si_el_transporte_falla_enviado_at_queda_vacio_pero_el_desafio_conserva_su_fecha_de_solicitud(): void
    {
        $this->usarTransporte([$this->permanente()]);
        $otp = $this->crearDesafio();

        $this->enviar($otp);

        $fila = DB::table('cf_accesos_otp')->where('id', $otp)->first();
        $this->assertNull($fila->enviado_at);
        $this->assertNotNull($fila->solicitado_at);
    }

    // ── Errores y reintento inmediato ─────────────────────────────────────────

    public function test_error_permanente_no_se_reintenta(): void
    {
        $t = $this->usarTransporte([$this->permanente(), 'NUNCA']);
        $e = $this->enviar($this->crearDesafio());

        $this->assertSame([Envio::FALLIDO_PERMANENTE, 1, 'permanente', 'SMTP_550'], [$e->estado, $e->intentos, $e->error_clase, $e->error_codigo]);
        $this->assertNull($e->aceptado_at);
        $this->assertSame(1, $t->llamadas);
        $this->assertSame([['fallido_permanente', 'SMTP_550']], DB::table('cf_envios_intentos')->get()->map(fn ($i) => [$i->resultado, $i->error_codigo])->all());
    }

    public function test_error_temporal_se_reintenta_una_sola_vez_de_inmediato_y_puede_aceptarse(): void
    {
        $t = $this->usarTransporte([$this->transitorio('421')]);
        $e = $this->enviar($this->crearDesafio());

        $this->assertSame([Envio::ACEPTADO, 2], [$e->estado, $e->intentos]);
        $this->assertSame(2, $t->llamadas);
        $this->assertSame([['fallido_temporal', 'SMTP_421'], ['aceptado', null]], DB::table('cf_envios_intentos')->orderBy('numero')->get()->map(fn ($i) => [$i->resultado, $i->error_codigo])->all());
        $this->assertNull($e->error_codigo, 'Al aceptarse, se limpia el último error');
    }

    public function test_dos_errores_temporales_dejan_el_envio_fallido_temporal_sin_un_tercer_intento(): void
    {
        $t = $this->usarTransporte([$this->transitorio(), $this->transitorio(), 'NUNCA']);
        $e = $this->enviar($this->crearDesafio());

        $this->assertSame([Envio::FALLIDO_TEMPORAL, 2, 'temporal', 'SMTP_451'], [$e->estado, $e->intentos, $e->error_clase, $e->error_codigo]);
        $this->assertSame(2, $t->llamadas);
        $this->assertSame(2, DB::table('cf_envios_intentos')->count());
    }

    public function test_el_reintento_inmediato_no_ocurre_si_el_codigo_dejo_de_estar_vigente_entre_intentos(): void
    {
        $t = $this->usarTransporte([$this->transitorio(), 'NUNCA']);
        $otp = $this->crearDesafio();
        $t->alEnviar = fn () => DB::table('cf_accesos_otp')->where('id', $otp)->update(['invalidado_at' => now()]);

        $e = $this->enviar($otp);

        $this->assertSame([Envio::FALLIDO_TEMPORAL, 1], [$e->estado, $e->intentos]);
        $this->assertSame(1, $t->llamadas, 'No se reenvía un código invalidado');
    }

    public function test_no_hay_reintento_tardio_ni_se_encola_nada_y_el_codigo_no_queda_guardado(): void
    {
        Queue::fake();
        $t = $this->usarTransporte([$this->transitorio(), $this->transitorio()]);
        $otp = $this->crearDesafio();
        $this->enviar($otp);

        // Un segundo intento sobre el mismo envío ya fallido NO hace nada (el código solo vivía en memoria).
        $this->enviar($otp);

        $this->assertSame(2, $t->llamadas);
        Queue::assertNothingPushed();
        $this->assertStringNotContainsString(self::CODIGO, $this->volcadoEnvios().json_encode(DB::table('cf_accesos_otp')->get()->all()));
        $fuente = file_get_contents(app_path('Support/CredentialFlow/Envios/ServicioEnvios.php'));
        $this->assertDoesNotMatchRegularExpression('/ShouldQueue|->later\(|->queue\(|dispatch\(|Bus::|sleep\(\s*[1-9]/', $fuente);
    }

    /** @return array<string,array{0:\Throwable,1:string,2:string}> */
    public static function fallos(): array
    {
        return [
            'SMTP 421' => [new TransportException('Expected response code "250" but got code "421", with message "421 x"'), 'temporal', 'SMTP_421'],
            'SMTP 452' => [new TransportException('Expected response code "250" but got code "452", with message "452 x"'), 'temporal', 'SMTP_452'],
            'SMTP 550' => [new TransportException('Expected response code "250" but got code "550", with message "550 x"'), 'permanente', 'SMTP_550'],
            'SMTP 554' => [new TransportException('Expected response code "250" but got code "554", with message "554 x"'), 'permanente', 'SMTP_554'],
            'autenticación' => [new TransportException('Failed to authenticate on SMTP server with username "usuario" using the following authenticators: "LOGIN".'), 'permanente', 'AUTENTICACION'],
            'autenticación 535' => [new TransportException('Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed".'), 'permanente', 'AUTENTICACION'],
            'conexión' => [new TransportException('Connection could not be established with host "mail.example.test:25": php_network_getaddresses failed'), 'temporal', 'CONEXION'],
            'tiempo de espera' => [new TransportException('Connection to "mail.example.test:25" timed out.'), 'temporal', 'TIMEOUT'],
            'dirección inválida' => [new RfcComplianceException('Email "no-es-correo" does not comply with addr-spec of RFC 2822.'), 'permanente', 'DIRECCION_INVALIDA'],
            'error desconocido' => [new RuntimeException('algo raro con ana.uno@example.test'), 'temporal', 'ERROR_DESCONOCIDO'],
        ];
    }

    #[DataProvider('fallos')]
    public function test_clasificacion_de_errores_temporales_y_permanentes(\Throwable $e, string $clase, string $codigo): void
    {
        $this->assertSame(['clase' => $clase, 'codigo' => $codigo], ClasificadorErrorEnvio::clasificar($e));
    }

    // ── Idempotencia y concurrencia ───────────────────────────────────────────

    public function test_un_desafio_produce_como_maximo_un_envio_logico_y_un_solo_correo(): void
    {
        $t = $this->usarTransporte();
        $otp = $this->crearDesafio();

        $a = $this->enviar($otp);
        $b = $this->enviar($otp);
        $c = $this->enviar($otp);

        $this->assertSame([$a->id, $a->id], [$b->id, $c->id]);
        $this->assertSame(1, Envio::count());
        $this->assertSame(1, DB::table('cf_envios_intentos')->count());
        $this->assertCount(1, $t->enviados);
        $this->assertSame('otp_acceso:otp:'.$otp, $a->clave_idempotencia);
    }

    public function test_la_clave_de_idempotencia_es_unica_a_nivel_de_base_de_datos(): void
    {
        $this->usarTransporte();
        $this->enviar($this->crearDesafio());
        $fila = (array) DB::table('cf_envios')->first();
        unset($fila['id']);

        $this->expectException(QueryException::class);
        DB::table('cf_envios')->insert($fila);
    }

    public function test_si_otro_proceso_ya_reclamo_el_envio_este_no_envia_nada(): void
    {
        $t = $this->usarTransporte();
        $otp = $this->crearDesafio();
        // Simula al «otro proceso»: ya registró el envío y lo tiene en «procesando».
        $ahora = now();
        DB::table('cf_envios')->insert([
            'tipo' => 'otp_acceso', 'categoria' => 'seguridad', 'plantilla' => 'otp_acceso', 'plantilla_version' => 1, 'origen_tipo' => 'otp', 'origen_id' => $otp,
            'destinatario_hash' => ServicioEnvios::hashDestinatario(self::CORREO), 'destinatario_mascara' => 'a***@example.test', 'clave_idempotencia' => 'otp_acceso:otp:'.$otp,
            'estado' => Envio::PROCESANDO, 'intentos' => 0, 'max_intentos' => 2, 'solicitado_at' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        $e = $this->enviar($otp);

        $this->assertSame(Envio::PROCESANDO, $e->estado);
        $this->assertSame(0, $t->llamadas);
        $this->assertSame(1, Envio::count());
    }

    public function test_dos_solicitudes_legitimas_generan_desafios_y_envios_distintos(): void
    {
        $t = $this->usarTransporte();
        $a = $this->crearDesafio();
        $b = $this->crearDesafio();

        $this->enviar($a);
        $this->enviar($b);

        $this->assertSame(2, Envio::count());
        $this->assertCount(2, $t->enviados);
    }

    // ── Desafío no enviable ───────────────────────────────────────────────────

    /** @return array<string,array{0:\Closure}> se evalúan dentro de la prueba (con la zona horaria de la aplicación) */
    public static function desafiosNoVigentes(): array
    {
        return [
            'expirado' => [fn () => ['expires_at' => now()->subMinute()]],
            'usado' => [fn () => ['usado_at' => now()]],
            'invalidado' => [fn () => ['invalidado_at' => now()]],
            'bloqueado' => [fn () => ['bloqueado_at' => now()]],
        ];
    }

    #[DataProvider('desafiosNoVigentes')]
    public function test_un_codigo_vencido_usado_invalidado_o_bloqueado_no_se_envia(\Closure $extra): void
    {
        $t = $this->usarTransporte();

        $e = $this->enviar($this->crearDesafio($extra()));

        $this->assertSame([Envio::FALLIDO_PERMANENTE, 'OTP_NO_VIGENTE', 0], [$e->estado, $e->error_codigo, $e->intentos]);
        $this->assertSame(0, $t->llamadas);
        $this->assertSame(0, DB::table('cf_envios_intentos')->count());
    }

    public function test_un_desafio_inexistente_no_envia_nada(): void
    {
        $t = $this->usarTransporte();

        $e = $this->enviar(987654);

        $this->assertSame('OTP_NO_VIGENTE', $e->error_codigo);
        $this->assertSame(0, $t->llamadas);
    }

    // ── Configuración y límite global ─────────────────────────────────────────

    public function test_el_interruptor_general_desactiva_los_envios(): void
    {
        $t = $this->usarTransporte();
        config(['credential_flow.correo.habilitado' => false]);

        $e = $this->enviar($this->crearDesafio());

        $this->assertSame([Envio::FALLIDO_PERMANENTE, 'ENVIO_DESACTIVADO'], [$e->estado, $e->error_codigo]);
        $this->assertSame(0, $t->llamadas);
    }

    public function test_el_transporte_log_no_se_usa_para_codigos(): void
    {
        $this->usarTransporte();
        config(['mail.default' => 'log']);

        $e = $this->enviar($this->crearDesafio());

        $this->assertSame([Envio::FALLIDO_PERMANENTE, 'TRANSPORTE_NO_PERMITIDO'], [$e->estado, $e->error_codigo]);
    }

    public function test_el_limite_global_por_minuto_corta_los_picos_sin_enviar(): void
    {
        $t = $this->usarTransporte();
        config(['credential_flow.correo.limite_global_por_minuto' => 2]);

        $estados = [];
        foreach (range(1, 4) as $_) {
            $estados[] = $this->enviar($this->crearDesafio())->only(['estado', 'error_codigo']);
        }

        $this->assertSame(Envio::ACEPTADO, $estados[0]['estado']);
        $this->assertSame(Envio::ACEPTADO, $estados[1]['estado']);
        $this->assertSame([Envio::FALLIDO_TEMPORAL, 'LIMITE_GLOBAL'], array_values($estados[2]));
        $this->assertSame('LIMITE_GLOBAL', $estados[3]['error_codigo']);
        $this->assertCount(2, $t->enviados);
    }

    public function test_el_limite_global_por_hora_tambien_aplica(): void
    {
        $t = $this->usarTransporte();
        config(['credential_flow.correo.limite_global_por_minuto' => 100, 'credential_flow.correo.limite_global_por_hora' => 1]);

        $this->enviar($this->crearDesafio());
        $e = $this->enviar($this->crearDesafio());

        $this->assertSame('LIMITE_GLOBAL', $e->error_codigo);
        $this->assertCount(1, $t->enviados);
    }

    // ── Privacidad y recuperación ante excepciones ────────────────────────────

    public function test_no_se_guarda_ni_se_registra_el_codigo_el_correo_el_documento_ni_el_mensaje_de_la_excepcion(): void
    {
        $this->usarTransporte([$this->transitorio(), $this->permanente()]);
        $otp = $this->crearDesafio();
        $this->enviar($otp);
        $this->usarTransporte([new RuntimeException('contraseña secreta y ana.uno@example.test')]);
        $this->enviar($this->crearDesafio());

        $guardado = $this->volcadoEnvios();
        $registrado = $this->volcadoRegistros();
        foreach ([self::CODIGO, self::CORREO, 'ana.uno', self::DOCUMENTO, self::NOMBRE, 'secreta', 'temporalmente no disponible', 'no existe'] as $dato) {
            $this->assertStringNotContainsString($dato, $guardado, "La base guarda «{$dato}»");
            $this->assertStringNotContainsString($dato, $registrado, "El log contiene «{$dato}»");
        }
        $this->assertStringContainsString('a***@example.test', $guardado);
        $this->assertSame(ServicioEnvios::hashDestinatario(self::CORREO), DB::table('cf_envios')->value('destinatario_hash'));
    }

    public function test_una_excepcion_inesperada_no_se_propaga_y_deja_constancia_tecnica(): void
    {
        $this->usarTransporte([new \Error('fallo grave con ana.uno@example.test'), new \Error('otra vez con ana.uno@example.test')]);
        $otp = $this->crearDesafio();

        $e = $this->enviar($otp);   // no lanza

        $this->assertSame(Envio::FALLIDO_TEMPORAL, $e->estado);
        $this->assertSame('ERROR_DESCONOCIDO', $e->error_codigo);
        $this->assertStringNotContainsString('ana.uno', $this->volcadoEnvios().$this->volcadoRegistros());
    }

    public function test_si_la_base_de_datos_falla_a_mitad_el_envio_no_se_queda_procesando_y_no_se_propaga(): void
    {
        $t = $this->usarTransporte();
        $otp = $this->crearDesafio();
        // El transporte acepta, pero al registrar el intento la tabla ya no existe: error interno inesperado.
        $t->alEnviar = fn () => Schema::drop('cf_envios_intentos');

        $e = $this->enviar($otp);

        $this->assertNotNull($e);
        $this->assertContains($e->estado, [Envio::FALLIDO_TEMPORAL, Envio::ACEPTADO]);
        $this->assertNotSame(Envio::PROCESANDO, $e->estado, 'Nunca queda «procesando» para siempre');
        $this->assertNotEmpty(array_filter($this->registros, fn ($r) => $r['nivel'] === 'error'));
        $this->assertStringNotContainsString(self::CORREO, $this->volcadoRegistros());
    }

    public function test_la_mascara_oculta_la_direccion(): void
    {
        $this->assertSame('a***@example.test', ServicioEnvios::mascara('ana.uno@example.test'));
        $this->assertSame('x***@dominio.co', ServicioEnvios::mascara('x@dominio.co'));
        $this->assertSame('***', ServicioEnvios::mascara('sin-arroba'));
        $this->assertSame('***', ServicioEnvios::mascara('@dominio.co'));
        $this->assertSame(64, strlen(ServicioEnvios::hashDestinatario('a@b.co')));
        $this->assertNotSame(ServicioEnvios::hashDestinatario('a@b.co'), ServicioEnvios::hashDestinatario('c@b.co'));
    }

    public function test_el_estado_solo_admite_valores_validos(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Envio::create(['tipo' => 'otp_acceso', 'plantilla' => 'x', 'origen_tipo' => 'otp', 'destinatario_hash' => str_repeat('a', 64), 'destinatario_mascara' => 'a***@b.co', 'clave_idempotencia' => 'k', 'estado' => 'enviado']);
    }
}
