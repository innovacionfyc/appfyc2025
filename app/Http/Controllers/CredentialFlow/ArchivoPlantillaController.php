<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Plantillas\ArchivoException;
use App\Support\CredentialFlow\Plantillas\ArchivoPlantillas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Archivo seguro de plantillas (C2). Las tres rutas exigen el rol super-admin (middleware) y el servicio lo vuelve a comprobar. Archivar y restaurar piden
 * confirmación explícita y motivo; nada se borra: diseño, PDF base, bases y emisiones se conservan.
 */
class ArchivoPlantillaController extends Controller
{
    public function __construct(private readonly ArchivoPlantillas $archivo) {}

    /** Qué pasaría al archivar (solo lectura): dependencias, variante QA, programaciones, bloqueos y sucesoras posibles. */
    public function resumen(Plantilla $plantilla): JsonResponse
    {
        return response()->json($this->archivo->resumen($plantilla));
    }

    public function archivar(Request $request, Plantilla $plantilla): RedirectResponse
    {
        $d = $request->validate([
            'motivo' => ['required', 'string', 'min:'.ArchivoPlantillas::MOTIVO_MIN, 'max:'.ArchivoPlantillas::MOTIVO_MAX],
            'sucesora_id' => ['nullable', 'integer', 'min:1'],
            'confirmacion' => ['accepted'],
        ], [], ['motivo' => 'motivo', 'sucesora_id' => 'plantilla sucesora', 'confirmacion' => 'confirmación']);

        try {
            $r = $this->archivo->archivar($plantilla, $request->user(), $d['motivo'], isset($d['sucesora_id']) ? (int) $d['sucesora_id'] : null);
        } catch (ArchivoException $e) {
            return back()->with('error', $e->getMessage());
        }

        $extra = count($r['archivadas']) > 1 ? ' Su variante de pruebas internas también quedó archivada.' : '';

        return to_route('credential-flow.plantillas.index')
            ->with('success', "La plantilla \"{$plantilla->nombre}\" quedó archivada. Sus bases y certificados se conservan.{$extra}");
    }

    public function restaurar(Request $request, Plantilla $plantilla): RedirectResponse
    {
        $d = $request->validate([
            'motivo' => ['required', 'string', 'min:'.ArchivoPlantillas::MOTIVO_MIN, 'max:'.ArchivoPlantillas::MOTIVO_MAX],
            'confirmacion' => ['accepted'],
        ], [], ['motivo' => 'motivo', 'confirmacion' => 'confirmación']);

        try {
            $r = $this->archivo->restaurar($plantilla, $request->user(), $d['motivo']);
        } catch (ArchivoException $e) {
            return back()->with('error', $e->getMessage());
        }

        $extra = count($r['restauradas']) > 1 ? ' Su variante de pruebas internas también se restauró.' : '';

        return to_route('credential-flow.plantillas.index')
            ->with('success', "La plantilla \"{$plantilla->nombre}\" vuelve a estar disponible.{$extra}");
    }
}
