<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\EmisionProgramada;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Usuario;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use App\Support\CredentialFlow\LogSeguro;
use App\Support\CredentialFlow\Programacion\PresentadorProgramacion;
use App\Support\CredentialFlow\Programacion\ProgramacionException;
use App\Support\CredentialFlow\Programacion\ServicioProgramacion;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emisión programada desde la pantalla de la base: ver el resumen antes de confirmar, programar, reprogramar, reconfirmar y cancelar. Todas las reglas
 * (interruptor, permiso, fechas, base activa, variante QA, huella, versión) las decide ServicioProgramacion; aquí solo se traducen a JSON con el mismo formato
 * de error que la emisión manual ({error:{code, message, detalles?}}).
 */
class ProgramacionController extends Controller
{
    public function __construct(
        private readonly ServicioProgramacion $servicio,
        private readonly PresentadorProgramacion $presentador,
    ) {}

    /** Lo que se programaría, sin guardar nada. */
    public function previa(Request $request, Lote $lote): JsonResponse
    {
        return $this->ejecutar(function () use ($request, $lote) {
            $v = $this->servicio->previsualizar($lote, $request->input('programada_para'), $this->usuario());
            $programador = $this->presentador->programador();
            $avisos = [];
            if ($programador['estado'] !== 'activo') {
                $avisos[] = $programador['texto'];
            }
            if ($v['oficiales'] === 0) {
                $avisos[] = 'No hay certificados oficiales pendientes: solo se emitirían las pruebas internas (QA).';
            }
            if ($v['qa'] === 0) {
                $avisos[] = 'No hay pruebas internas (QA) pendientes en esta base.';
            }

            return response()->json(['previa' => [
                'programada_para' => $v['fecha']->format('Y-m-d\TH:i'),
                'programada_para_texto' => PresentadorProgramacion::fechaLegible($v['fecha']),
                'oficiales' => $v['oficiales'],
                'qa' => $v['qa'],
                'plantilla' => $lote->plantilla?->nombre,
                'variante_qa' => $v['variante_qa_id'] !== null ? Plantilla::query()->whereKey($v['variante_qa_id'])->value('nombre') : app(ServicioQa::class)->plantillaPara($lote)?->nombre,
                'avisos' => $avisos,
                // Lo que se confirma debe ser exactamente esto: programar y reprogramar lo devuelven y el servicio lo compara con la base de ese momento.
                'sello' => $v['sello'],
            ]]);
        });
    }

    public function store(Request $request, Lote $lote): JsonResponse
    {
        return $this->ejecutar(function () use ($request, $lote) {
            $this->servicio->programar($lote, $request->input('programada_para'), $this->usuario(), $this->sello($request));

            return response()->json(['programacion' => $this->presentador->para($lote)], 201);
        });
    }

    public function reprogramar(Request $request, Lote $lote, EmisionProgramada $programacion): JsonResponse
    {
        $this->deLaBase($lote, $programacion);

        return $this->ejecutar(function () use ($request, $lote, $programacion) {
            $this->servicio->reprogramar($programacion, $request->input('programada_para'), $this->usuario(), $this->version($request), $this->sello($request));

            return response()->json(['programacion' => $this->presentador->para($lote)]);
        });
    }

    public function reconfirmar(Request $request, Lote $lote, EmisionProgramada $programacion): JsonResponse
    {
        $this->deLaBase($lote, $programacion);

        return $this->ejecutar(function () use ($request, $lote, $programacion) {
            $this->servicio->reconfirmar($programacion, $this->usuario(), $this->version($request), $this->sello($request));

            return response()->json(['programacion' => $this->presentador->para($lote)]);
        });
    }

    public function cancelar(Request $request, Lote $lote, EmisionProgramada $programacion): JsonResponse
    {
        $this->deLaBase($lote, $programacion);

        return $this->ejecutar(function () use ($request, $lote, $programacion) {
            $this->servicio->cancelar($programacion, $this->usuario(), $request->input('motivo'), $this->version($request));

            return response()->json(['programacion' => $this->presentador->para($lote)]);
        });
    }

    private function usuario(): Usuario
    {
        $usuario = Auth::user();
        // El permiso se revisa antes que cualquier otro dato de la petición (el servicio lo vuelve a revisar con la misma regla).
        if (! $usuario instanceof Usuario || ! ServicioProgramacion::autorizado($usuario)) {
            throw new ProgramacionException(ProgramacionException::SIN_PERMISO, 'No tienes permiso para programar emisiones.', 403);
        }

        return $usuario;
    }

    /** Una programación solo se gestiona desde su propia base. */
    private function deLaBase(Lote $lote, EmisionProgramada $programacion): void
    {
        abort_if($programacion->lote_id !== $lote->id, 404);
    }

    /** La versión que el administrador estaba viendo: si otra persona la cambió mientras tanto, el servicio rechaza la acción en lugar de pisarla. */
    private function version(Request $request): int
    {
        $version = filter_var($request->input('version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($version === false) {
            throw new ProgramacionException(ProgramacionException::CAMBIO_CONCURRENTE, 'Falta la versión de la programación que estabas viendo. Recarga la página y vuelve a intentarlo.', 422);
        }

        return $version;
    }

    /** El sello del resumen que el administrador revisó: sin él no se aprueba nada desde la pantalla. */
    private function sello(Request $request): string
    {
        $sello = $request->input('sello');
        if (! is_string($sello) || preg_match('/^[0-9a-f]{64}$/', $sello) !== 1) {
            throw new ProgramacionException(ProgramacionException::RESUMEN_DESACTUALIZADO, 'Falta el resumen que revisaste. Recarga la página, revisa el resumen y vuelve a confirmar.', 422);
        }

        return $sello;
    }

    private function ejecutar(Closure $accion): JsonResponse
    {
        try {
            return $accion();
        } catch (ProgramacionException $e) {
            Log::warning('Credential Flow: programación rechazada', ['codigo' => $e->codigo]);

            return response()->json(['error' => ['code' => $e->codigo, 'message' => $e->getMessage()]], $e->estadoHttp);
        } catch (EmisionException $e) {
            Log::warning('Credential Flow: programación rechazada por la emisión', ['codigo' => $e->codigo]);
            $error = ['code' => $e->codigo, 'message' => $e->getMessage()];
            if ($e->detalles !== []) {
                $error['detalles'] = $e->detalles;
            }

            return response()->json(['error' => $error], $e->estadoHttp);
        } catch (GeneracionCredencialException $e) {
            Log::warning('Credential Flow: programación rechazada por la generación', ['codigo' => $e->codigo]);

            return response()->json(['error' => ['code' => $e->codigo, 'message' => $e->getMessage()]], 422);
        } catch (Throwable $e) {
            Log::error('Credential Flow: error inesperado al programar una emisión', ['error' => LogSeguro::resumen($e)]);

            return response()->json(['error' => [
                'code' => 'ERROR_INESPERADO',
                'message' => 'No se pudo completar la operación. Inténtalo de nuevo; si continúa, avisa al equipo técnico.',
            ]], 500);
        }
    }
}
