<?php

namespace App\Support\CredentialFlow\Portal;

use App\Support\CredentialFlow\Envios\ServicioEnvios;
use App\Support\CredentialFlow\Identidad\IdentidadFlags;
use App\Support\CredentialFlow\Identidad\ScopeIdentidadAprobado;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

use function Illuminate\Support\defer;

/**
 * Desafíos OTP del portal. Nada se guarda en claro: documento, correo, IP y user-agent como HMAC; el código, con bcrypt.
 *
 * `solicitar` NUNCA revela si hubo envío: sale igual aunque el documento no exista, el correo no coincida, el documento esté en
 * revisión, haya un límite alcanzado o un bloqueo activo. El envío del correo se hace DESPUÉS de responder (mismo tiempo de respuesta
 * con y sin coincidencia). Un OTP nuevo invalida el anterior activo del mismo documento + correo.
 *
 * Límites (documento + correo): 60 s entre envíos, 3 por hora; (documento): 8 por día. Bloqueo temporal de `bloqueo_minutos` cuando
 * hay `bloqueos_para_bloquear` desafíos bloqueados por intentos del documento en esa ventana. Nunca permanente.
 */
class ServicioOtp
{
    public function __construct(private readonly AccesoPortal $acceso, private readonly ServicioEnvios $envios, private readonly ScopeIdentidadAprobado $scope) {}

    /** Código de 6 dígitos con generador criptográficamente seguro (random_int), con ceros a la izquierda. */
    public static function generarCodigo(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    public static function hashDocumento(string $clave): string
    {
        return Hmac::de('documento', $clave);
    }

    public static function hashCorreo(string $correoNormalizado): string
    {
        return Hmac::de('correo', $correoNormalizado);
    }

    /** @return bool true si se creó un desafío y se programó el envío (solo para pruebas; la respuesta pública no depende de ello). */
    public function solicitar(string $documento, string $correo, ?string $ip, ?string $userAgent): bool
    {
        // Defensa en profundidad (11C.1): con el portal apagado no se crea ningún desafío ni se programa ningún correo, entre por donde entre la llamada.
        if (! PortalFlag::habilitado()) {
            return false;
        }

        $clave = AccesoPortal::claveDocumento($documento);
        $normalizado = AccesoPortal::correoNormalizado($correo);

        // Trabajo equivalente cuando no hay coincidencia, para no delatarla por el tiempo de respuesta.
        // Alcance determinado AHORA y ligado al desafío: documento completo (null) o un grupo de nombre conservador.
        $scope = null;
        if ($clave === null || $normalizado === null) {
            $alcance = null;
        } elseif (IdentidadFlags::decisionesHabilitadas()) {
            // 10B-3B-1 (interruptor ENCENDIDO): único resolver. Solo el alcance histórico normal o un scope aprobado emiten OTP; todo lo demás (ambiguo, bloqueado,
            // soporte, conflicto, sin vía) sigue la rama pública uniforme de abajo, sin revelar el motivo.
            $r = $this->scope->resolver($clave, $normalizado);
            $alcance = match (true) {
                $r->esHistorico() => ['grupo' => $r->grupoHistorico],
                // Un scope aprobado NUNCA deja `grupo_hash` en NULL (que significaría «documento completo»): lleva el propio scope_hash, que no coincide con
                // ningún grupo real, de modo que cualquier lector que lo use como grupo niega todo (falla cerrada).
                $r->esAprobado() => ['grupo' => $r->scopeHash],
                default => null,
            };
            $scope = $r->esAprobado() ? $r : null;
            if ($scope !== null && ! $scope->aplicable()) {
                // Un scope masivo (≥ 100 certificados) solo se habilita con segunda aprobación y el interruptor masivo (10B-3C-3); si no, no se emite OTP (rama uniforme).
                Log::notice('Credential Flow: scope de identidad masivo no aplicable; no se emite OTP.');
                $alcance = null;
                $scope = null;
            }
        } else {
            $alcance = $this->acceso->alcance($clave, $normalizado);
        }
        if ($alcance === null) {
            Hash::make(self::generarCodigo());

            return false;
        }

        $doc = self::hashDocumento($clave);
        $cor = self::hashCorreo($normalizado);
        if (! $this->puedeEmitir($doc, $cor)) {
            Hash::make(self::generarCodigo());

            return false;
        }

        $codigo = self::generarCodigo();
        $ahora = Carbon::now();
        $otpId = DB::transaction(function () use ($doc, $cor, $codigo, $ahora, $ip, $userAgent, $alcance, $scope) {
            DB::table('cf_accesos_otp')->where('documento_hash', $doc)->where('correo_hash', $cor)->whereNull('usado_at')->whereNull('invalidado_at')->whereNull('bloqueado_at')
                ->update(['invalidado_at' => $ahora, 'updated_at' => $ahora]);

            return DB::table('cf_accesos_otp')->insertGetId([
                'documento_hash' => $doc, 'correo_hash' => $cor, 'grupo_hash' => $alcance['grupo'], 'otp_hash' => Hash::make($codigo),
                ...($scope === null ? [] : ['scope_hash' => $scope->scopeHash, 'scope_decisiones' => json_encode($scope->decisionIds), 'scope_grupos' => count($scope->grupos)]),
                'expires_at' => $ahora->copy()->addMinutes((int) config('credential_flow.portal.otp_vigencia_minutos')),
                'intentos' => 0, 'solicitado_at' => $ahora, 'enviado_at' => null, 'ip_hash' => Hmac::ip($ip), 'user_agent_hash' => $userAgent === null ? null : Hmac::de('ua', $userAgent),
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }, 3);   // reintenta ante un deadlock de InnoDB (dos solicitudes simultáneas del mismo documento+correo)

        // Después de responder: el tiempo de la respuesta no depende de que haya (o no) coincidencia.
        // Después de responder: el tiempo de la respuesta no depende de que haya (o no) coincidencia. El servicio registra el envío (un
        // desafío = un envío lógico), reintenta una sola vez de inmediato si el error es temporal y NUNCA propaga un fallo a esta solicitud.
        defer(fn () => $this->envios->enviarOtp((int) $otpId, $normalizado, $codigo));

        return true;
    }

    private function puedeEmitir(string $doc, string $cor): bool
    {
        $ahora = Carbon::now();
        $p = config('credential_flow.portal');
        $base = DB::table('cf_accesos_otp');

        if ((clone $base)->where('documento_hash', $doc)->whereNotNull('bloqueado_at')->where('bloqueado_at', '>', $ahora->copy()->subMinutes($p['bloqueo_minutos']))->count() >= $p['bloqueos_para_bloquear']) {
            return false;
        }
        if ((clone $base)->where('documento_hash', $doc)->where('correo_hash', $cor)->where('solicitado_at', '>', $ahora->copy()->subSeconds($p['reenvio_segundos']))->exists()) {
            return false;
        }
        if ((clone $base)->where('documento_hash', $doc)->where('correo_hash', $cor)->where('solicitado_at', '>', $ahora->copy()->subHour())->count() >= $p['otp_max_por_hora']) {
            return false;
        }

        return (clone $base)->where('documento_hash', $doc)->where('solicitado_at', '>', $ahora->copy()->subDay())->count() < $p['otp_max_por_dia'];
    }

    /**
     * Valida el código contra el desafío activo y devuelve su alcance (`grupo`: null = documento completo; `scope`: el scope aprobado revalidado, o null) o null si falla. Falla de forma uniforme (expirado, incorrecto, bloqueado, usado, inexistente).
     * Cada intento incrementa el contador bajo bloqueo de fila; al quinto fallo el desafío queda bloqueado.
     */
    public function validar(string $clave, string $correoNormalizado, string $codigo): ?array
    {
        if (preg_match('/^\d{6}$/', $codigo) !== 1) {
            return null;
        }
        $doc = self::hashDocumento($clave);
        $cor = self::hashCorreo($correoNormalizado);
        $max = (int) config('credential_flow.portal.otp_max_intentos');

        return DB::transaction(function () use ($clave, $correoNormalizado, $doc, $cor, $codigo, $max) {
            $fila = DB::table('cf_accesos_otp')->where('documento_hash', $doc)->where('correo_hash', $cor)
                ->whereNull('usado_at')->whereNull('invalidado_at')->whereNull('bloqueado_at')->where('expires_at', '>', Carbon::now())
                ->orderByDesc('id')->lockForUpdate()->first();
            if ($fila === null) {
                Hash::make($codigo); // coste comparable al de comprobar un código real

                return null;
            }

            $intentos = $fila->intentos + 1;
            $ok = Hash::check($codigo, $fila->otp_hash);
            $cambios = ['intentos' => $intentos, 'updated_at' => Carbon::now()];
            $scope = null;
            $scopeCambio = false;
            if ($ok && $fila->scope_hash !== null) {
                // Desafío con SCOPE APROBADO (10B-3B-1): se revalida contra las decisiones de AHORA. Cualquier cambio (decisión revocada o nueva, otro scope) o el
                // interruptor apagado ⇒ el desafío se invalida y no hay scope: un OTP nunca conserva ni amplía un scope que ya no es el vigente.
                $scope = IdentidadFlags::decisionesHabilitadas() ? $this->scope->resolver($clave, $correoNormalizado) : null;
                $scopeCambio = $scope === null || ! $scope->esAprobado() || ! $scope->aplicable() || $scope->scopeHash !== $fila->scope_hash
                    || $scope->decisionIds !== array_map('intval', (array) json_decode((string) $fila->scope_decisiones, true)) || count($scope->grupos) !== (int) $fila->scope_grupos;
            }
            if ($ok && $scopeCambio) {
                $ok = false;
                $cambios['invalidado_at'] = Carbon::now();
            } elseif ($ok) {
                $cambios['usado_at'] = Carbon::now();
            } elseif ($intentos >= $max) {
                $cambios['bloqueado_at'] = Carbon::now();
            }
            DB::table('cf_accesos_otp')->where('id', $fila->id)->update($cambios);

            // Desafío histórico: el alcance es el del desafío (determinado al solicitarlo); nunca se recalcula aquí. Con scope aprobado, `scope` lleva la lista
            // CONGELADA y `grupo` NO debe usarse (es el scope_hash): lo consume 10B-3B-2; hasta entonces el controlador no abre ninguna sesión con él.
            return $ok ? ['grupo' => $fila->grupo_hash, 'scope' => $scope] : null;
        });
    }
}
