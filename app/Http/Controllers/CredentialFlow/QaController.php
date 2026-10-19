<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Acciones administrativas de los contactos de control (QA): incorporarlos a una base existente y designar la plantilla de pruebas internas. */
class QaController extends Controller
{
    public function __construct(private readonly ServicioQa $qa) {}

    public function incorporar(Request $request, Lote $lote): RedirectResponse
    {
        if (! ServicioQa::disponible() || $this->qa->contactos()->isEmpty()) {
            return back()->with('error', 'Todavía no hay contactos de control (QA) configurados.');
        }
        $r = $this->qa->incorporar($lote, $request->user()?->id);

        return back()->with('success', $r['creados'] > 0
            ? "Se agregaron {$r['creados']} contactos de control (QA). No cuentan como participantes reales."
            : 'Esta base ya tiene todos los contactos de control (QA).'.($r['omitidos'] > 0 ? " {$r['omitidos']} comparten documento con un participante real y no se tocaron." : ''));
    }

    public function variante(Request $request, Plantilla $plantilla): RedirectResponse
    {
        $datos = $request->validate(['variante_qa_id' => ['nullable', 'integer']]);
        try {
            $this->qa->asignarVariante($plantilla, isset($datos['variante_qa_id']) ? Plantilla::query()->findOrFail($datos['variante_qa_id']) : null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Se guardó la variante de pruebas internas de esta plantilla.');
    }

    public function plantilla(Request $request, Plantilla $plantilla): RedirectResponse
    {
        $datos = $request->validate(['es_plantilla_qa' => ['required', 'boolean']]);
        try {
            $this->qa->designarPlantilla($plantilla, (bool) $datos['es_plantilla_qa']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $datos['es_plantilla_qa'] ? 'La plantilla se usará para los certificados de pruebas internas (QA).' : 'La plantilla ya no es la de pruebas internas.');
    }
}
