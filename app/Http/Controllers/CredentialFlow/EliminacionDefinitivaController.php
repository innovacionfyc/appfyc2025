<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Services\CredentialFlow\EliminacionDefinitivaService;
use App\Support\CredentialFlow\Eliminacion\EliminacionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Eliminar definitivamente»: acción SEPARADA del «Eliminar» de siempre (que sigue ocultando sin borrar).
 * Las rutas resuelven también los registros ya eliminados (soft delete), que es justo lo que hay que poder liberar.
 * El navegador solo envía la palabra de confirmación: ninguna ruta de archivo viaja en la petición.
 */
class EliminacionDefinitivaController extends Controller
{
    public const PALABRA = 'ELIMINAR';

    public function __construct(private readonly EliminacionDefinitivaService $servicio) {}

    public function resumenLote(Lote $lote): JsonResponse
    {
        return response()->json(['resumen' => $this->servicio->resumenLote($lote)]);
    }

    public function lote(Request $request, Lote $lote)
    {
        $request->validate(['confirmacion' => ['required', 'string', 'in:'.self::PALABRA]], [
            'confirmacion.*' => 'Para continuar escribe '.self::PALABRA.' tal como aparece.',
        ]);

        try {
            $r = $this->servicio->eliminarLote($lote->id);
        } catch (EliminacionException $e) {
            return to_route('credential-flow.lotes.index')->with('error', $e->getMessage());
        }

        $mensaje = "La base \"{$r['nombre']}\" se eliminó definitivamente. Se liberaron ".self::megabytes($r['bytes']).'.';
        if ($r['residuos']) {
            return to_route('credential-flow.lotes.index')->with('error', $mensaje.' Quedaron archivos temporales por limpiar: avisa al equipo técnico.');
        }

        return to_route('credential-flow.lotes.index')->with('success', $mensaje);
    }

    public function resumenPlantilla(Plantilla $plantilla): JsonResponse
    {
        return response()->json(['resumen' => $this->servicio->resumenPlantilla($plantilla)]);
    }

    public function plantilla(Request $request, Plantilla $plantilla)
    {
        $request->validate(['confirmacion' => ['required', 'string', 'in:'.self::PALABRA]], [
            'confirmacion.*' => 'Para continuar escribe '.self::PALABRA.' tal como aparece.',
        ]);

        try {
            $r = $this->servicio->eliminarPlantilla($plantilla->id);
        } catch (EliminacionException $e) {
            return to_route('credential-flow.plantillas.index')->with('error', $e->getMessage());
        }

        $mensaje = "La plantilla \"{$r['nombre']}\" se eliminó definitivamente. Se liberaron ".self::megabytes($r['bytes']).'.';
        if ($r['residuos']) {
            // La plantilla ya no existe; solo quedó un archivo pendiente de limpieza (queda en el registro técnico y en la auditoría).
            return to_route('credential-flow.plantillas.index')->with('error', $mensaje.' Quedó un archivo pendiente de limpieza: avisa al equipo técnico.');
        }

        return to_route('credential-flow.plantillas.index')->with('success', $mensaje);
    }

    /** «1,2 MB» (coma decimal); por debajo de 0,1 MB se dice «menos de 0,1 MB». */
    public static function megabytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 MB';
        }
        $mb = $bytes / 1048576;

        return $mb < 0.1 ? 'menos de 0,1 MB' : number_format($mb, 1, ',', '.').' MB';
    }
}
