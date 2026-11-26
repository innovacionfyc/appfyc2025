<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\CorreccionBase;
use App\Support\CredentialFlow\Emisiones\CorreccionBaseException;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\ReemisorBase;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use App\Support\CredentialFlow\LogSeguro;
use App\Support\CredentialFlow\Participantes\DatosDeParticipante;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «Corregir toda la base»: revisar qué certificados vigentes de la base quedaron desactualizados, ver el PDF anterior y el propuesto de una persona y
 * confirmar la reemisión (oficiales y QA por separado). Ver CorreccionBase. Ver el antes y el después no escribe nada; nada de esto envía correos.
 */
class CorreccionBaseController extends Controller
{
    public function __construct(private readonly CorreccionBase $correccion, private readonly ServicioQa $qa) {}

    public function plan(Lote $lote): JsonResponse
    {
        return response()->json(['plan' => $this->correccion->planear($lote)]);
    }

    /** El PDF vigente tal como está guardado (verificado contra su huella), para verlo en la pantalla. No cuenta como descarga. */
    public function anterior(Lote $lote, Emision $emision): HttpResponse|JsonResponse
    {
        abort_if($this->correccion->emisionDe($lote, $emision) === null, 404);

        return $this->pdf(fn () => AlmacenEmisiones::leerVerificado($emision), "anterior-{$emision->id}-v{$emision->version}.pdf");
    }

    /** Lo que saldría HOY para esa persona con los datos actuales de la base: plantilla de la base (oficial) o su variante de pruebas internas (QA). */
    public function propuesto(Lote $lote, Emision $emision): HttpResponse|JsonResponse
    {
        $participante = $this->correccion->emisionDe($lote, $emision) ?? abort(404);
        $plantilla = $participante->es_control_qa ? $this->qa->plantillaPara($lote) : $lote->plantilla;
        if ($plantilla === null) {
            return response()->json(['error' => ['code' => EmisionException::PLANTILLA_NO_DISPONIBLE, 'message' => $participante->es_control_qa
                ? 'La plantilla de esta base no tiene su variante de pruebas internas (QA).' : 'La plantilla de esta base ya no está disponible.']], 409);
        }

        return $this->pdf(fn () => GeneradorCredencialPdf::generar($plantilla, DatosDeParticipante::para($participante, $lote)), "propuesto-{$emision->id}.pdf");
    }

    public function reemitir(Request $request, Lote $lote): JsonResponse
    {
        $d = $request->validate([
            'huella' => ['required', 'string', 'size:64'],
            'motivo' => ['required', 'string', 'min:'.ReemisorBase::MOTIVO_MIN, 'max:'.ReemisorBase::MOTIVO_MAX],
            'confirmo' => ['accepted'],
        ], [
            'motivo.required' => 'Escribe el motivo de la corrección.',
            'motivo.min' => 'El motivo debe tener al menos '.ReemisorBase::MOTIVO_MIN.' caracteres.',
            'motivo.max' => 'El motivo no puede superar los '.ReemisorBase::MOTIVO_MAX.' caracteres.',
            'confirmo.accepted' => 'Marca la casilla para confirmar que los códigos anteriores quedarán revocados.',
        ]);

        try {
            return response()->json($this->correccion->ejecutar($lote, $d['huella'], trim($d['motivo']), $request->user()));
        } catch (CorreccionBaseException $e) {
            // 409 siempre: no se reemitió nada y el detalle (código y mensaje) explica por qué.
            return response()->json($e->resultado + ['error' => ['code' => $e->error->codigo, 'message' => $e->error->getMessage(), 'detalles' => array_slice($e->error->detalles, 0, 20)]], 409);
        } catch (EmisionException $e) {
            // Otra corrección de la misma base en curso (cerrojo): no empezó nada.
            return response()->json(['estado' => CorreccionBase::SIN_CAMBIOS, 'grupos' => [], 'correos_enviados' => 0,
                'error' => ['code' => $e->codigo, 'message' => $e->getMessage(), 'detalles' => []]], $e->estadoHttp);
        }
    }

    /** @param  callable():string  $bytes */
    private function pdf(callable $bytes, string $nombre): HttpResponse|JsonResponse
    {
        try {
            return response($bytes(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$nombre.'"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (EmisionException $e) {
            return response()->json(['error' => ['code' => $e->codigo, 'message' => $e->getMessage()]], $e->estadoHttp);
        } catch (GeneracionCredencialException $e) {
            return response()->json(['error' => ['code' => $e->codigo, 'message' => $e->getMessage()]], 422);
        } catch (Throwable $e) {
            Log::error('Credential Flow: error al mostrar el antes y el después de una corrección', ['error' => LogSeguro::resumen($e)]);

            return response()->json(['error' => ['code' => 'ERROR_INESPERADO', 'message' => 'No se pudo mostrar el PDF. Inténtalo de nuevo.']], 500);
        }
    }
}
