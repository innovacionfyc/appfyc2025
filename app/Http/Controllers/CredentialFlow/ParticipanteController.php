<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CredentialFlow\Concerns\RespondePdfDeCredencial;
use App\Http\Requests\CredentialFlow\ParticipanteRequest;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use App\Support\CredentialFlow\Participantes\DatosDeParticipante;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Alta, edición, eliminación y PDF individual de un participante de un lote. */
class ParticipanteController extends Controller
{
    use RespondePdfDeCredencial;

    public function store(ParticipanteRequest $request, Lote $lote)
    {
        $participante = $lote->participantes()->create($request->datos());

        Movimiento::registrar(
            tipo: 'registro',
            modulo: 'credential-flow',
            descripcion: 'Se agregó manualmente un participante a un lote de Credential Flow',
            extra: ['lote_id' => $lote->id, 'participante_id' => $participante->id],
        );

        return back()->with('success', 'El participante se agregó correctamente.');
    }

    public function update(ParticipanteRequest $request, Lote $lote, Participante $participante)
    {
        $this->pertenece($lote, $participante);
        $participante->update($request->datos());

        Movimiento::registrar(
            tipo: 'actualizacion',
            modulo: 'credential-flow',
            descripcion: 'Se editó un participante de un lote de Credential Flow',
            extra: ['lote_id' => $lote->id, 'participante_id' => $participante->id],
        );

        return back()->with('success', 'El participante se actualizó correctamente.');
    }

    public function destroy(Lote $lote, Participante $participante)
    {
        $this->pertenece($lote, $participante);

        // Con una emisión vigente hay que revocarla primero; con solo revocadas se permite (las emisiones permanecen).
        if ($participante->emisionVigente()->exists()) {
            return back()->with('error', 'Este participante tiene un certificado vigente. Revócalo antes de eliminarlo.');
        }

        $participante->delete();

        Movimiento::registrar(
            tipo: 'eliminacion',
            modulo: 'credential-flow',
            descripcion: 'Se eliminó un participante de un lote de Credential Flow',
            extra: ['lote_id' => $lote->id, 'participante_id' => $participante->id],
        );

        return back()->with('success', 'El participante se eliminó correctamente.');
    }

    /**
     * PDF individual REAL: plantilla del lote + diseño guardado + datos del participante y del lote. No se
     * guarda el PDF ni se registra Movimiento (no es una emisión). El nombre del archivo no lleva datos personales.
     */
    public function pdf(Lote $lote, Participante $participante): HttpResponse|JsonResponse
    {
        $this->pertenece($lote, $participante);
        $plantilla = $lote->plantilla ?? abort(404);

        return $this->respuestaPdf(
            fn () => GeneradorCredencialPdf::generar($plantilla, DatosDeParticipante::para($participante, $lote)),
            'PDF de participante',
            ['lote' => $lote->id, 'participante' => $participante->id],
            'credencial-'.$participante->id.'.pdf',
        );
    }

    /** El participante de la URL debe pertenecer al lote de la URL. */
    private function pertenece(Lote $lote, Participante $participante): void
    {
        abort_unless($participante->lote_id === $lote->id, 404);
    }
}
