<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CabecerasVerificacionPublica;
use App\Models\CredentialFlow\Emision;
use App\Support\CredentialFlow\Emisiones\CodigoEmision;
use App\Support\CredentialFlow\Legado\ResultadoVerificacionLegado;
use App\Support\CredentialFlow\Legado\VerificacionLegado;
use App\Support\CredentialFlow\Verificacion\UrlVerificacion;
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

        // Código LEGADO (numérico corto): camino SEPARADO del moderno, con su propio límite (son cortos y enumerables).
        if (! CodigoEmision::valido($codigo) && VerificacionLegado::esFormatoLegado($codigo)) {
            return $this->mostrarLegado($request, $codigo, $claveFallos, $maxFallos);
        }

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

    /**
     * Verificación de un código LEGADO. Los códigos son cortos y enumerables, así que además del límite general y del de fallos hay
     * un límite ESPECÍFICO por IP (por minuto y por hora) que cuenta TODA consulta de código legado. Respuestas uniformes: un código
     * inexistente y una anomalía de colisión entre pares distintos son idénticos (404, sin eco). Sin PDF y sin datos personales.
     */
    private function mostrarLegado(Request $request, string $codigo, string $claveFallos, int $maxFallos): Response
    {
        $min = 'cf-verificacion-legado:min:'.$request->ip();
        $hora = 'cf-verificacion-legado:hora:'.$request->ip();
        foreach ([[$min, (int) config('credential_flow.verificacion.limite_legado_por_minuto')], [$hora, (int) config('credential_flow.verificacion.limite_legado_por_hora')]] as [$clave, $max]) {
            if (RateLimiter::tooManyAttempts($clave, $max)) {
                return self::limitada($request, RateLimiter::availableIn($clave));
            }
        }
        RateLimiter::hit($min, 60);
        RateLimiter::hit($hora, 3600);

        $r = app(VerificacionLegado::class)->resolver($codigo);

        if ($r->estado === ResultadoVerificacionLegado::COLISION) {
            // Error técnico SIN datos personales: huella corta del código y de la IP (nunca el código ni la IP en claro).
            Log::error('Credential Flow: código legado compartido por pares distintos', ['pares' => $r->pares, 'codigo_sha' => substr(hash('sha256', $codigo), 0, 12), 'ip_sha' => substr(hash('sha256', (string) $request->ip()), 0, 12)]);
        }
        if ($r->esInexistente()) {
            RateLimiter::hit($claveFallos, 60);

            return $this->vista($request, ['estado' => 'no_encontrada'], 404);
        }

        $comunes = ['codigo' => $codigo, 'evento' => (string) $r->evento, 'anio' => $r->anio];

        return match ($r->estado) {
            ResultadoVerificacionLegado::VALIDO => $this->vista($request, $comunes + ['estado' => 'legado_valido']),
            ResultadoVerificacionLegado::REVOCADO => $this->vista($request, $comunes + ['estado' => 'legado_revocado']),
            ResultadoVerificacionLegado::REEMPLAZADO => $this->vista($request, $comunes + ['estado' => 'legado_reemplazado', 'enlace_moderno' => $r->codigoModerno === null ? null : UrlVerificacion::para($r->codigoModerno)]),
            default => $this->vista($request, ['estado' => 'legado_revision', 'codigo' => $codigo]),
        };
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
