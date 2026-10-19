<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CredentialFlow\Concerns\RespondePdfDeCredencial;
use App\Http\Requests\CredentialFlow\ParticipanteRequest;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Emisiones\ServicioVistaPrevia;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use App\Support\CredentialFlow\Participantes\CorreosDeParticipante;
use App\Support\CredentialFlow\Participantes\DatosDeParticipante;
use App\Support\CredentialFlow\Portal\ServicioOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Alta, edición, eliminación y PDF individual de un participante de un lote. */
class ParticipanteController extends Controller
{
    use RespondePdfDeCredencial;

    public function __construct(private readonly CorreosDeParticipante $correos) {}

    public function store(ParticipanteRequest $request, Lote $lote)
    {
        $participante = DB::transaction(function () use ($request, $lote) {
            $p = $lote->participantes()->create($request->datos());
            if ($request->correos() !== null) {
                $this->correos->guardar($p, $request->correos(), $request->user()?->id);
            }

            return $p;
        });

        Movimiento::registrar(
            tipo: 'registro',
            modulo: 'credential-flow',
            descripcion: 'Se agregó manualmente un participante a un lote de Credential Flow',
            extra: ['lote_id' => $lote->id, 'participante_id' => $participante->id],
        );

        return back()->with('success', 'El participante se agregó correctamente.'.$this->avisos($request));
    }

    public function update(ParticipanteRequest $request, Lote $lote, Participante $participante)
    {
        $this->pertenece($lote, $participante);
        $claveAnterior = $participante->documento_clave;
        $cambioCorreo = ['cambio' => false, 'retirados' => [], 'agregados' => []];
        DB::transaction(function () use ($request, $participante, &$cambioCorreo) {
            $participante->update($request->datos());
            if ($request->correos() !== null) {
                $cambioCorreo = $this->correos->guardar($participante, $request->correos(), $request->user()?->id);
            }
        });

        // Si cambió el documento, los códigos ya emitidos con el documento anterior dejan de servir (el acceso es documento + correo).
        if ($claveAnterior !== $participante->documento_clave) {
            foreach ($this->correos->normalizadosActuales($participante) as $correo) {
                ServicioOtp::invalidarPendientes($claveAnterior, $correo);
            }
        }

        Movimiento::registrar(
            tipo: 'actualizacion',
            modulo: 'credential-flow',
            descripcion: 'Se editó un participante de un lote de Credential Flow',
            extra: ['lote_id' => $lote->id, 'participante_id' => $participante->id],
        );

        $mensaje = 'El participante se actualizó correctamente.';
        if ($cambioCorreo['cambio']) {
            $mensaje .= ' El correo se actualizó'.($cambioCorreo['retirados'] !== [] ? ' y se retiró el acceso con el correo anterior.' : '.');
        }

        return back()->with('success', $mensaje.$this->avisos($request));
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

        $respuesta = $this->respuestaPdf(
            fn () => GeneradorCredencialPdf::generar($plantilla, DatosDeParticipante::para($participante, $lote)),
            'PDF de participante',
            ['lote' => $lote->id, 'participante' => $participante->id],
            'credencial-'.$participante->id.'.pdf',
        );

        // Una vista previa exitosa de un participante REAL cuenta como «vista previa generada» con la plantilla actual (hay que confirmarla aparte).
        if (! $participante->es_control_qa && $respuesta->getStatusCode() === 200) {
            app(ServicioVistaPrevia::class)->registrarGenerada($lote);
        }

        return $respuesta;
    }

    private function avisos(ParticipanteRequest $request): string
    {
        return $request->avisosCorreo() === [] ? '' : ' Aviso: '.implode(' ', $request->avisosCorreo());
    }

    /** El participante de la URL debe pertenecer al lote de la URL. */
    private function pertenece(Lote $lote, Participante $participante): void
    {
        abort_unless($participante->lote_id === $lote->id, 404);
    }
}
