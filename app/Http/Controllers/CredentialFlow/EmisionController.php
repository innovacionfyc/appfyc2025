<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Requests\CredentialFlow\MotivoEmisionRequest;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\EmisorCredencial;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Emisiones\GeneradorZip;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Emisión oficial: emitir (individual y de pendientes), descargar el PDF almacenado, revocar, reemitir, ZIP e
 * historial. Los errores esperados salen como JSON con un código estable y un mensaje seguro (sin rutas ni trazas).
 */
class EmisionController extends Controller
{
    public function __construct(
        private readonly EmisorCredencial $emisor,
        private readonly EmisorLote $emisorLote,
        private readonly GeneradorZip $zip,
    ) {}

    public function emitir(Lote $lote, Participante $participante): JsonResponse|HttpResponse
    {
        return $this->ejecutar(function () use ($lote, $participante) {
            $emision = $this->emisor->emitir($participante, $lote, Auth::id());

            return response()->json(['emision' => $this->representar($emision)], 201);
        });
    }

    public function revocar(MotivoEmisionRequest $request, Emision $emision): JsonResponse|HttpResponse
    {
        return $this->ejecutar(fn () => response()->json(['emision' => $this->representar($this->emisor->revocar($emision, $request->motivo(), Auth::id()))]));
    }

    public function reemitir(MotivoEmisionRequest $request, Emision $emision): JsonResponse|HttpResponse
    {
        return $this->ejecutar(fn () => response()->json(['emision' => $this->representar($this->emisor->reemitir($emision, $request->motivo(), Auth::id()))], 201));
    }

    /** Sirve SIEMPRE el PDF almacenado (nunca regenera), tras comprobar existencia, tamaño y SHA-256. */
    public function descargar(Emision $emision): JsonResponse|HttpResponse
    {
        return $this->ejecutar(fn () => response(AlmacenEmisiones::leerVerificado($emision), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="credencial-'.$emision->id.'-v'.$emision->version.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    public function resumen(Lote $lote): JsonResponse
    {
        return response()->json(['resumen' => $this->emisorLote->resumen($lote)]);
    }

    public function emitirLote(Lote $lote): JsonResponse|HttpResponse
    {
        return $this->ejecutar(fn () => response()->json(['resultado' => $this->emisorLote->emitirPendientes($lote, Auth::id())], 201));
    }

    public function zip(Lote $lote): JsonResponse|HttpResponse
    {
        return $this->ejecutar(fn () => $this->zip->paraLote($lote));
    }

    /** Historial de emisiones del lote (más recientes primero), paginado. */
    public function historial(Request $request, Lote $lote): JsonResponse
    {
        $pagina = Emision::query()
            ->where('lote_id', $lote->id)
            ->with(['emisor:id,correo_principal', 'revocador:id,correo_principal'])
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json([
            'emisiones' => $pagina->getCollection()->map(fn (Emision $e) => $this->representar($e))->values(),
            'pagina' => $pagina->currentPage(),
            'paginas' => $pagina->lastPage(),
            'total' => $pagina->total(),
        ]);
    }

    /** @return array<string,mixed> */
    private function representar(Emision $e): array
    {
        return [
            'id' => $e->id,
            'codigo' => $e->codigo,
            'participante_id' => $e->participante_id,
            'participante' => $e->datos_snapshot['nombre_completo'] ?? null,
            'version' => $e->version,
            'estado' => $e->estado,
            'reemplaza_id' => $e->reemplaza_id,
            'emitido_at' => $e->emitido_at?->toIso8601String(),
            'emitido_por' => $e->emisor?->correo_principal,
            'revocado_at' => $e->revocado_at?->toIso8601String(),
            'motivo_revocacion' => $e->motivo_revocacion,
            'pdf_bytes' => $e->pdf_bytes,
        ];
    }

    /** @param  Closure(): (JsonResponse|HttpResponse)  $accion */
    private function ejecutar(Closure $accion): JsonResponse|HttpResponse
    {
        try {
            return $accion();
        } catch (EmisionException $e) {
            Log::warning('Credential Flow: emisión rechazada', ['codigo' => $e->codigo]);

            $error = ['code' => $e->codigo, 'message' => $e->getMessage()];
            if ($e->detalles !== []) {
                $error['detalles'] = $e->detalles;
            }

            return response()->json(['error' => $error], $e->estadoHttp);
        } catch (GeneracionCredencialException $e) {
            Log::warning('Credential Flow: emisión no generada', ['codigo' => $e->codigo]);

            return response()->json(['error' => ['code' => $e->codigo, 'message' => $e->getMessage()]], 422);
        } catch (Throwable $e) {
            Log::error('Credential Flow: error inesperado en una emisión', ['error' => $e::class.': '.$e->getMessage()]);

            return response()->json(['error' => [
                'code' => 'ERROR_INESPERADO',
                'message' => 'No se pudo completar la operación. Inténtalo de nuevo; si continúa, avisa al equipo técnico.',
            ]], 500);
        }
    }
}
