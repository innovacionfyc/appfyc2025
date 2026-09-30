<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CabecerasVerificacionPublica;
use App\Models\CredentialFlow\Emision;
use App\Support\CredentialFlow\Emisiones\CodigoEmision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verificación PÚBLICA de una credencial por su código de emisión (sin login). Lee EXCLUSIVAMENTE cf_emisiones
 * y su datos_snapshot (lo emitido manda; nunca participante, lote ni plantilla vivos). No expone ids, rutas,
 * documento ni el motivo de revocación, y un código con formato inválido o inexistente recibe exactamente la
 * misma respuesta 404 (sin eco del código).
 */
class VerificacionPublicaController extends Controller
{
    public function mostrar(Request $request, string $codigo): Response
    {
        $claveFallos = 'cf-verificacion-fallos:'.$request->ip();
        $maxFallos = (int) config('credential_flow.verificacion.limite_fallos_por_minuto');

        if (RateLimiter::tooManyAttempts($claveFallos, $maxFallos)) {
            return self::limitada($request, RateLimiter::availableIn($claveFallos));
        }

        $codigo = strtoupper(trim($codigo));
        $emision = CodigoEmision::valido($codigo)
            ? Emision::query()->select(['id', 'codigo', 'estado', 'datos_snapshot', 'emitido_at'])->where('codigo', $codigo)->first()
            : null;

        if ($emision === null) {
            RateLimiter::hit($claveFallos, 60);

            return $this->vista($request, ['estado' => 'no_encontrada'], 404);
        }

        $datos = (array) $emision->datos_snapshot;
        $comunes = [
            'codigo' => $emision->codigo,
            'evento' => (string) ($datos['evento'] ?? ''),
            'fecha_evento' => (string) ($datos['fecha'] ?? ''),
            'emitida' => $emision->emitido_at?->copy()->timezone('America/Bogota')->locale('es')->translatedFormat('j \d\e F \d\e Y'),
        ];

        if ($emision->estado === Emision::EMITIDA) {
            return $this->vista($request, $comunes + [
                'estado' => 'valida',
                'nombre' => (string) ($datos['nombre_completo'] ?? ''),
                'intensidad' => (string) ($datos['intensidad_horaria'] ?? ''),
            ]);
        }

        // Revocada: sin nombre, sin documento, sin motivo, sin usuario ni versión.
        return $this->vista($request, $comunes + ['estado' => 'revocada']);
    }

    /** Respuesta 429 amable con Retry-After. Se usa también desde el limitador general. */
    public static function limitada(Request $request, int $segundos, array $cabeceras = []): Response
    {
        // Único registro de la verificación pública: IP truncada y user-agent recortado; nunca el código consultado.
        Log::warning('Credential Flow: verificación pública limitada', self::datosParaLog($request));

        $nonce = CabecerasVerificacionPublica::nonce($request);

        // El limitador puede ejecutarse antes que CabecerasVerificacionPublica: la 429 lleva las mismas cabeceras.
        return CabecerasVerificacionPublica::aplicar(response()->view('credential-flow.verificacion', [
            'estado' => 'limitada',
            'entidad' => config('credential_flow.verificacion.entidad'),
            'nonce' => $nonce,
        ], 429, ['Retry-After' => (string) max(1, $segundos)] + $cabeceras), $nonce);
    }

    private function vista(Request $request, array $datos, int $estado = 200): Response
    {
        return response()->view('credential-flow.verificacion', $datos + [
            'entidad' => config('credential_flow.verificacion.entidad'),
            'nonce' => CabecerasVerificacionPublica::nonce($request),
        ], $estado);
    }

    /** IP truncada (IPv4: último octeto a 0; IPv6: primeros 3 grupos) y user-agent recortado para el log de 429. */
    public static function datosParaLog(Request $request): array
    {
        $ip = (string) $request->ip();
        $truncada = str_contains($ip, ':')
            ? implode(':', array_slice(explode(':', $ip), 0, 3)).'::'
            : preg_replace('/\.\d+$/', '.0', $ip);

        return ['ip' => $truncada, 'ua' => Str::limit((string) $request->userAgent(), 80, '')];
    }
}
