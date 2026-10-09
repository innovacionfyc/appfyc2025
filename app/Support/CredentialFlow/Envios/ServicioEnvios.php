<?php

namespace App\Support\CredentialFlow\Envios;

use App\Mail\CodigoAccesoMail;
use App\Models\CredentialFlow\Envio;
use App\Models\CredentialFlow\EnvioIntento;
use App\Support\CredentialFlow\Portal\Hmac;
use App\Support\CredentialFlow\Portal\PortalFlag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mailer\SentMessage;
use Throwable;

/**
 * Envío auditable de correos de Credential Flow (Fase 9). Hoy solo el OTP de acceso al portal.
 *
 *  - IDEMPOTENCIA: un desafío OTP produce COMO MÁXIMO un envío lógico (clave única `otp_acceso:otp:{id}`). Si dos procesos intentan
 *    enviar el mismo desafío, `INSERT … IGNORE` + un «reclamo» atómico `pendiente → procesando` hacen que solo uno envíe.
 *  - ESTADO REAL: `aceptado_por_transporte` solo si el transporte aceptó el mensaje (sin excepción). No significa que llegó a la bandeja.
 *  - SIN OTP EN REPOSO: el código solo existe en memoria durante la llamada. Por eso hay como máximo UN reintento INMEDIATO, dentro del
 *    mismo proceso y solo tras un error temporal; nunca reintentos tardíos. Un código vencido, usado, invalidado o bloqueado no se envía.
 *  - PRIVACIDAD: se guarda el HMAC y la máscara del destinatario, el código técnico del error y la clase (temporal/permanente). Nunca el
 *    código, el cuerpo, el documento ni el mensaje de la excepción. Ningún fallo se propaga: quien llama (respuesta pública) no se entera.
 */
final class ServicioEnvios
{
    private const REF_MAX = 190;

    public static function hashDestinatario(string $correoNormalizado): string
    {
        return Hmac::de('envio_destinatario', $correoNormalizado);
    }

    /** `ana.perez@dominio.com` → `a***@dominio.com` (nunca la dirección completa). */
    public static function mascara(string $correo): string
    {
        $arroba = strrpos($correo, '@');
        if ($arroba === false || $arroba === 0) {
            return '***';
        }

        return mb_substr($correo, 0, 1).'***@'.substr($correo, $arroba + 1);
    }

    public static function claveOtp(int $otpId): string
    {
        return Envio::TIPO_OTP.':otp:'.$otpId;
    }

    /** Envía el OTP del desafío `$otpId` (o no hace nada si ya se procesó). Nunca lanza: devuelve el envío o null si ni siquiera pudo registrarse. */
    public function enviarOtp(int $otpId, string $correoNormalizado, string $codigo): ?Envio
    {
        // Defensa en profundidad (11C.1): con el portal apagado no se registra ni se envía ningún correo del portal.
        if (! PortalFlag::habilitado()) {
            return null;
        }

        $envio = null;
        try {
            $envio = $this->registrar($otpId, $correoNormalizado);
            if (! $this->reclamar($envio)) {
                return $envio->fresh();   // otro proceso ya lo tomó o ya se resolvió: no se envía dos veces
            }
            $this->procesar($envio, $otpId, $correoNormalizado, $codigo);
        } catch (Throwable $e) {
            $this->abortar($envio, $e);
        }

        return $envio?->fresh();
    }

    private function registrar(int $otpId, string $correo): Envio
    {
        $plantilla = config('credential_flow.correo.plantillas.'.Envio::TIPO_OTP);
        $ahora = Carbon::now();
        DB::table('cf_envios')->insertOrIgnore([
            'tipo' => Envio::TIPO_OTP, 'categoria' => 'seguridad', 'plantilla' => Envio::TIPO_OTP, 'plantilla_version' => (int) $plantilla['version'],
            'origen_tipo' => 'otp', 'origen_id' => $otpId, 'destinatario_hash' => self::hashDestinatario($correo), 'destinatario_mascara' => self::mascara($correo),
            'clave_idempotencia' => self::claveOtp($otpId), 'estado' => Envio::PENDIENTE, 'intentos' => 0, 'max_intentos' => (int) $plantilla['max_intentos'],
            'solicitado_at' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        return Envio::query()->where('clave_idempotencia', self::claveOtp($otpId))->firstOrFail();
    }

    /** Reclamo atómico: solo UN proceso pasa de «pendiente» a «procesando». */
    private function reclamar(Envio $envio): bool
    {
        return Envio::query()->whereKey($envio->id)->where('estado', Envio::PENDIENTE)->update(['estado' => Envio::PROCESANDO, 'updated_at' => Carbon::now()]) === 1;
    }

    private function procesar(Envio $envio, int $otpId, string $correo, string $codigo): void
    {
        $cfg = config('credential_flow.correo');
        $transporte = (string) config('mail.default');

        if (! $cfg['habilitado']) {
            $this->cerrar($envio, Envio::FALLIDO_PERMANENTE, ClasificadorErrorEnvio::PERMANENTE, 'ENVIO_DESACTIVADO');

            return;
        }
        // El transporte `log` escribiría el código en un archivo de texto.
        if ($transporte === 'log' && ! $cfg['permitir_transporte_log']) {
            $this->cerrar($envio, Envio::FALLIDO_PERMANENTE, ClasificadorErrorEnvio::PERMANENTE, 'TRANSPORTE_NO_PERMITIDO');

            return;
        }
        if (! $this->otpVigente($otpId)) {
            $this->cerrar($envio, Envio::FALLIDO_PERMANENTE, ClasificadorErrorEnvio::PERMANENTE, 'OTP_NO_VIGENTE');

            return;
        }
        if ($this->superaLimiteGlobal($cfg)) {
            $this->cerrar($envio, Envio::FALLIDO_TEMPORAL, ClasificadorErrorEnvio::TEMPORAL, 'LIMITE_GLOBAL');

            return;
        }

        $max = max(1, (int) $envio->max_intentos);
        $ultimo = null;
        for ($n = 1; $n <= $max; $n++) {
            $inicio = Carbon::now();
            $t0 = hrtime(true);
            Envio::query()->whereKey($envio->id)->update([
                'intentos' => $n, 'ultimo_intento_at' => $inicio, 'updated_at' => $inicio,
            ] + ($n === 1 ? ['primer_intento_at' => $inicio] : []));

            try {
                $enviado = Mail::to($correo)->send(new CodigoAccesoMail($codigo));
                $ref = $this->referencia($enviado);
                $this->registrarIntento($envio, $n, EnvioIntento::ACEPTADO, $inicio, $t0, $transporte, $ref);
                $this->aceptar($envio, $otpId, $ref);

                return;
            } catch (Throwable $e) {
                $ultimo = ClasificadorErrorEnvio::clasificar($e);   // se descarta el mensaje de la excepción
                $this->registrarIntento($envio, $n, $ultimo['clase'] === ClasificadorErrorEnvio::PERMANENTE ? EnvioIntento::FALLIDO_PERMANENTE : EnvioIntento::FALLIDO_TEMPORAL, $inicio, $t0, $transporte, null, $ultimo);
            }

            // Reintento inmediato SOLO tras un error temporal, mientras el código siga vigente y haya intentos disponibles.
            if ($ultimo['clase'] !== ClasificadorErrorEnvio::TEMPORAL || $n === $max || ! $this->otpVigente($otpId)) {
                break;
            }
            usleep(max(0, (int) $cfg['reintento_inmediato_ms']) * 1000);
        }

        $this->cerrar($envio, $ultimo['clase'] === ClasificadorErrorEnvio::PERMANENTE ? Envio::FALLIDO_PERMANENTE : Envio::FALLIDO_TEMPORAL, $ultimo['clase'], $ultimo['codigo']);
    }

    private function otpVigente(int $otpId): bool
    {
        return DB::table('cf_accesos_otp')->where('id', $otpId)->whereNull('usado_at')->whereNull('invalidado_at')->whereNull('bloqueado_at')->where('expires_at', '>', Carbon::now())->exists();
    }

    /** Límite global de envíos lógicos por minuto y por hora (protección contra picos accidentales). */
    private function superaLimiteGlobal(array $cfg): bool
    {
        $min = 'cf-envios-global:minuto';
        $hora = 'cf-envios-global:hora';
        if (RateLimiter::tooManyAttempts($min, (int) $cfg['limite_global_por_minuto']) || RateLimiter::tooManyAttempts($hora, (int) $cfg['limite_global_por_hora'])) {
            return true;
        }
        RateLimiter::hit($min, 60);
        RateLimiter::hit($hora, 3600);

        return false;
    }

    /** Referencia del servidor de correo SOLO si existe (p. ej. «queued as XXXX»); si no, null. Nunca se guarda el texto de depuración. */
    private function referencia(mixed $enviado): ?string
    {
        // Laravel envuelve el resultado de Symfony en su propio SentMessage.
        $enviado = $enviado instanceof \Illuminate\Mail\SentMessage ? $enviado->getSymfonySentMessage() : $enviado;
        if (! $enviado instanceof SentMessage) {
            return null;
        }
        $debug = (string) $enviado->getDebug();
        if (preg_match('/queued as ([A-Za-z0-9._\-]{4,100})/i', $debug, $m) === 1) {
            return mb_substr($m[1], 0, self::REF_MAX);
        }

        return null;
    }

    private function registrarIntento(Envio $envio, int $n, string $resultado, Carbon $inicio, int|float $t0, string $transporte, ?string $ref, ?array $error = null): void
    {
        DB::table('cf_envios_intentos')->insert([
            'envio_id' => $envio->id, 'numero' => $n, 'resultado' => $resultado, 'iniciado_at' => $inicio, 'finalizado_at' => Carbon::now(),
            'duracion_ms' => (int) round((hrtime(true) - $t0) / 1e6), 'transporte' => mb_substr($transporte, 0, 30), 'proveedor_referencia' => $ref,
            'error_clase' => $error['clase'] ?? null, 'error_codigo' => $error['codigo'] ?? null, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    private function aceptar(Envio $envio, int $otpId, ?string $ref): void
    {
        $ahora = Carbon::now();
        Envio::query()->whereKey($envio->id)->update(['estado' => Envio::ACEPTADO, 'aceptado_at' => $ahora, 'proveedor_referencia' => $ref, 'error_clase' => null, 'error_codigo' => null, 'updated_at' => $ahora]);
        // `enviado_at` del desafío: SOLO ahora, cuando el transporte aceptó el mensaje.
        DB::table('cf_accesos_otp')->where('id', $otpId)->whereNull('enviado_at')->update(['enviado_at' => $ahora, 'updated_at' => $ahora]);
    }

    private function cerrar(Envio $envio, string $estado, string $clase, string $codigo): void
    {
        Envio::query()->whereKey($envio->id)->update(['estado' => $estado, 'error_clase' => $clase, 'error_codigo' => $codigo, 'updated_at' => Carbon::now()]);
        // Solo identificadores técnicos: ni destinatario, ni código, ni mensaje.
        Log::warning('Credential Flow: correo no aceptado por el transporte', ['envio_id' => $envio->id, 'tipo' => $envio->tipo, 'clase' => $clase, 'codigo' => $codigo]);
    }

    /** Fallo inesperado (p. ej. la base de datos): se deja constancia sin propagar y sin datos personales. */
    private function abortar(?Envio $envio, Throwable $e): void
    {
        try {
            Log::error('Credential Flow: error interno al procesar un envío', ['envio_id' => $envio?->id, 'tipo_excepcion' => $e::class]);
            if ($envio !== null) {
                Envio::query()->whereKey($envio->id)->whereIn('estado', [Envio::PENDIENTE, Envio::PROCESANDO])
                    ->update(['estado' => Envio::FALLIDO_TEMPORAL, 'error_clase' => ClasificadorErrorEnvio::TEMPORAL, 'error_codigo' => 'ERROR_INTERNO', 'updated_at' => Carbon::now()]);
            }
        } catch (Throwable) {
            // Último recurso: nunca romper la solicitud pública.
        }
    }
}
