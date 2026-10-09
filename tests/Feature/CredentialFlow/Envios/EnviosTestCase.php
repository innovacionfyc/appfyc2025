<?php

namespace Tests\Feature\CredentialFlow\Envios;

use App\Support\CredentialFlow\Envios\ServicioEnvios;
use App\Support\CredentialFlow\Portal\ServicioOtp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\CredentialFlow\Historico\PortalTestCase;

/** Base de las pruebas de Fase 9: portal sintético + un transporte de correo SIMULADO (nunca SMTP real ni correos externos). */
abstract class EnviosTestCase extends PortalTestCase
{
    protected const CODIGO = '482915';

    /** @var list<array{nivel:string,mensaje:string,contexto:array}> */
    protected array $registros = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Los registros (logs) se capturan en memoria para comprobar que no llevan datos personales ni códigos; no se escribe ningún archivo.
        config(['logging.default' => 'null']);
        Log::listen(function ($m) {
            $this->registros[] = ['nivel' => $m->level, 'mensaje' => (string) $m->message, 'contexto' => $m->context];
        });
        config(['credential_flow.correo.reintento_inmediato_ms' => 0]);
        RateLimiter::clear('cf-envios-global:minuto');
        RateLimiter::clear('cf-envios-global:hora');
    }

    /** Sustituye el correo falso por el gestor REAL de Laravel con el transporte simulado (así se ejecuta todo el camino de envío). */
    protected function usarTransporte(array $secuencia = []): TransportePrueba
    {
        $transporte = new TransportePrueba;
        $transporte->secuencia = $secuencia;
        app()->forgetInstance('mail.manager');
        Mail::clearResolvedInstance('mail.manager');
        app('mail.manager')->extend('prueba', fn () => $transporte);
        config(['mail.mailers.prueba' => ['transport' => 'prueba'], 'mail.default' => 'prueba']);

        return $transporte;
    }

    /** Desafío OTP «vigente» creado directamente (sin pasar por el portal): el código en claro solo existe en la prueba. */
    protected function crearDesafio(array $extra = []): int
    {
        $ahora = Carbon::now();

        return DB::table('cf_accesos_otp')->insertGetId($extra + [
            'documento_hash' => ServicioOtp::hashDocumento('1000001'), 'correo_hash' => ServicioOtp::hashCorreo(self::CORREO), 'grupo_hash' => null,
            'otp_hash' => 'hash-de-prueba', 'expires_at' => $ahora->copy()->addMinutes(10), 'intentos' => 0, 'solicitado_at' => $ahora, 'enviado_at' => null,
            'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
    }

    protected function servicio(): ServicioEnvios
    {
        return app(ServicioEnvios::class);
    }

    /** Todo lo que Fase 9 guarda, como texto, para comprobar que no hay datos personales ni códigos. */
    protected function volcadoEnvios(): string
    {
        return json_encode([DB::table('cf_envios')->get()->all(), DB::table('cf_envios_intentos')->get()->all()]);
    }

    protected function volcadoRegistros(): string
    {
        return json_encode($this->registros);
    }
}
