<?php

namespace App\Http\Controllers\CredentialFlow\Concerns;

use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use App\Support\CredentialFlow\LogSeguro;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Respuesta común de los endpoints que generan un PDF de credencial (prueba e individual). Los errores
 * esperados salen como 422 JSON con un código estable y un mensaje seguro; el detalle va solo al log.
 */
trait RespondePdfDeCredencial
{
    /**
     * @param  Closure(): string  $generar  devuelve los bytes del PDF
     * @param  array<string,mixed>  $contexto  datos de log (ids, nunca datos personales)
     */
    protected function respuestaPdf(Closure $generar, string $etiqueta, array $contexto, string $nombreArchivo): HttpResponse|JsonResponse
    {
        try {
            $bytes = $generar();
        } catch (GeneracionCredencialException $e) {
            Log::warning("Credential Flow: {$etiqueta} no generado", $contexto + ['codigo' => $e->codigo, 'elemento' => $e->elementoId]);

            return response()->json(['error' => ['code' => $e->codigo, 'message' => $e->getMessage()]], 422);
        } catch (Throwable $e) {
            Log::error("Credential Flow: error inesperado al generar {$etiqueta}", $contexto + ['error' => LogSeguro::resumen($e)]);

            return response()->json(['error' => [
                'code' => 'ERROR_INESPERADO',
                'message' => 'No se pudo generar el PDF. Inténtalo de nuevo; si continúa, avisa al equipo técnico.',
            ]], 500);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nombreArchivo.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
