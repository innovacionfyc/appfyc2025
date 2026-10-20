<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\Importacion;
use App\Support\CredentialFlow\Importacion\ImportacionException;
use App\Support\CredentialFlow\Importacion\ServicioImportaciones;
use App\Support\CredentialFlow\LogSeguro;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * API administrativa de la importación inteligente (F3). Cada borrador es PRIVADO de quien lo creó: otro administrador (incluso super-admin) recibe 404.
 * Solo `confirmar` crea una base; analizar, consultar, corregir, validar y rechazar nunca crean participantes, bases, eventos ni emisiones.
 * Protegida por auth + rol (grupo de rutas), CSRF (middleware web), límites de frecuencia y el interruptor `importacion_inteligente.activa`.
 */
class ImportacionInteligenteController extends Controller
{
    public function __construct(private readonly ServicioImportaciones $servicio) {}

    /** Mis borradores en revisión (solo resumen, sin datos personales). */
    public function index(Request $request): JsonResponse
    {
        $this->servicio->limpiar();
        $borradores = Importacion::query()->where('usuario_id', $request->user()->id)->where('estado', Importacion::EN_REVISION)->orderByDesc('id')->limit(50)
            ->get(['id', 'estado', 'version', 'archivo_nombre', 'resumen', 'expira_at', 'created_at']);

        return response()->json(['borradores' => $borradores->map(fn (Importacion $i) => [
            'id' => $i->id, 'estado' => $i->estado, 'version' => $i->version, 'archivo' => $i->archivo_nombre, 'resumen' => $i->resumen,
            'expira_at' => $i->expira_at?->toIso8601String(), 'creado_at' => $i->created_at?->toIso8601String(),
        ])->values()]);
    }

    /** Analiza el Excel y crea o recupera su borrador. NO crea nada más. */
    public function analizar(Request $request): JsonResponse
    {
        $request->validate(['archivo' => ['required', 'file']], ['archivo.required' => 'Selecciona un archivo .xlsx.', 'archivo.file' => 'El archivo no se pudo subir. Inténtalo de nuevo.']);

        return $this->proteger(function () use ($request) {
            [$importacion, $recuperado] = $this->servicio->analizar($request->file('archivo'), $request->user()->id);

            return response()->json(['recuperado' => $recuperado, 'borrador' => $this->servicio->vista($importacion)], $recuperado ? 200 : 201);
        }, 'analizar');
    }

    public function show(Request $request, Importacion $importacion): JsonResponse
    {
        $this->propio($request, $importacion);

        return $this->proteger(function () use ($importacion) {
            $this->servicio->limpiar();
            $fresco = Importacion::query()->findOrFail($importacion->id);

            return response()->json(['borrador' => $this->servicio->vista($fresco)]);
        }, 'consultar');
    }

    /** Registra correcciones y confirmaciones. Exige la `version` que se está viendo. */
    public function corregir(Request $request, Importacion $importacion): JsonResponse
    {
        $this->propio($request, $importacion);
        $request->validate(['version' => ['required', 'integer', 'min:1']], ['version.required' => 'Falta la versión del borrador que estás revisando.']);

        return $this->proteger(function () use ($request, $importacion) {
            $actualizado = $this->servicio->corregir($importacion, $request->integer('version'), $request->except(['version']));

            return response()->json(['borrador' => $this->servicio->vista($actualizado)]);
        }, 'corregir');
    }

    /** ¿Está listo para importar? Solo lee: no escribe en ninguna tabla. */
    public function validar(Request $request, Importacion $importacion): JsonResponse
    {
        $this->propio($request, $importacion);

        return $this->proteger(function () use ($importacion) {
            $fresco = Importacion::query()->findOrFail($importacion->id);
            if (! $fresco->enRevision() || $fresco->vencido()) {
                throw new ImportacionException($fresco->estado === Importacion::CONFIRMADO ? ImportacionException::YA_CONFIRMADO : ImportacionException::NO_EN_REVISION, 'El borrador ya no está en revisión.', 409);
            }
            $e = $this->servicio->evaluar($fresco);
            unset($e['plan']);

            return response()->json(['version' => $fresco->version] + $e);
        }, 'validar');
    }

    /** Acción independiente y explícita: crea la base. Requiere `confirmar: true` y la versión revisada. */
    public function confirmar(Request $request, Importacion $importacion): JsonResponse
    {
        $this->propio($request, $importacion);
        $request->validate(
            ['version' => ['required', 'integer', 'min:1'], 'confirmar' => ['required', 'accepted']],
            ['version.required' => 'Falta la versión del borrador que estás confirmando.', 'confirmar.required' => 'Confirma explícitamente que quieres crear la base.', 'confirmar.accepted' => 'Confirma explícitamente que quieres crear la base.']
        );

        return $this->proteger(function () use ($request, $importacion) {
            $lote = $this->servicio->confirmar($importacion, $request->integer('version'), $request->user()->id);

            return response()->json(['lote_id' => $lote->id, 'redirect' => route('credential-flow.lotes.show', $lote, false), 'mensaje' => 'La base se creó. No se emitió ningún certificado.'], 201);
        }, 'confirmar');
    }

    public function rechazar(Request $request, Importacion $importacion): JsonResponse
    {
        $this->propio($request, $importacion);

        return $this->proteger(function () use ($importacion) {
            $this->servicio->rechazar($importacion);

            return response()->json(['ok' => true]);
        }, 'rechazar');
    }

    /** Un borrador es de quien lo creó; para los demás no existe. */
    private function propio(Request $request, Importacion $importacion): void
    {
        abort_unless($importacion->usuario_id === $request->user()->id, 404);
    }

    private function proteger(callable $accion, string $operacion): JsonResponse
    {
        try {
            return $accion();
        } catch (ImportacionException $e) {
            $error = ['code' => $e->codigo, 'message' => $e->getMessage()];
            if ($e->detalles !== []) {
                $error['detalles'] = $e->detalles;
            }

            return response()->json(['error' => $error], $e->estadoHttp);
        } catch (Throwable $e) {
            if ($e instanceof HttpResponseException || $e instanceof ValidationException) {
                throw $e;
            }
            Log::error('Credential Flow: error inesperado en la importación inteligente', ['operacion' => $operacion, 'error' => LogSeguro::resumen($e)]);

            return response()->json(['error' => ['code' => 'ERROR_INESPERADO', 'message' => 'No se pudo completar la operación. No se cambió nada; inténtalo de nuevo y, si sigue igual, avisa al equipo técnico.']], 500);
        }
    }
}
