<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Emisiones\ServicioVistaPrevia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Pasos «Plantilla» y «Vista previa» del recorrido de una base: cambiar la plantilla (solo sin emisiones) y confirmar la vista previa revisada. */
class FlujoLoteController extends Controller
{
    public function __construct(private readonly ServicioVistaPrevia $vistaPrevia) {}

    public function plantilla(Request $request, Lote $lote): RedirectResponse
    {
        $d = $request->validate(['plantilla_id' => ['required', 'integer']]);
        try {
            $this->vistaPrevia->cambiarPlantilla($lote, Plantilla::query()->findOrFail($d['plantilla_id']));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'La plantilla de la base se actualizó. Revisa la vista previa y confírmala antes de emitir.');
    }

    public function confirmarVistaPrevia(Lote $lote): RedirectResponse
    {
        try {
            $this->vistaPrevia->confirmar($lote);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Plantilla y vista previa confirmadas: ya puedes emitir.');
    }
}
