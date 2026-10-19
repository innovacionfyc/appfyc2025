<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Lote;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\GeneradorZip;
use App\Support\CredentialFlow\Eventos\AsociacionAmbiguaException;
use App\Support\CredentialFlow\Eventos\ServicioEventos;
use App\Support\CredentialFlow\Historico\ConsultaHistorica;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Eventos de certificación dentro del MISMO administrador: asocia las bases (presencial, virtual…) a un evento, separa los participantes de control (QA) de los
 * reales (por participante, también dentro de bases mixtas) y descarga los certificados vigentes del evento en un ZIP. Los eventos históricos siguen en el módulo Histórico (solo lectura).
 */
class EventoCertificacionController extends Controller
{
    public function __construct(private readonly ServicioEventos $servicio, private readonly GeneradorZip $zip) {}

    public function index(ConsultaHistorica $historico): Response
    {
        $nativos = EventoCertificacion::query()->where('origen', EventoCertificacion::ORIGEN_CREDENTIAL_FLOW)->orderByDesc('anio')->orderByDesc('id')->get();
        $eventos = $nativos->map(fn (EventoCertificacion $e) => ['id' => $e->id, 'nombre' => $e->nombre, 'anio' => $e->anio, 'fecha_texto' => $e->fecha_texto] + $this->servicio->resumen($e));

        $sinEvento = Lote::query()->whereNull('evento_id')->withCount('participantes')->latest()->limit(100)->get()->map(function (Lote $l) {
            $propuesta = null;
            if (trim((string) ($l->datos_comunes['evento'] ?? '')) !== '') {
                $e = $this->servicio->evaluar($l);
                $propuesta = ['accion' => $e['accion'], 'evento' => $e['evento']?->nombre, 'motivos' => $e['motivos'], 'candidatos' => $e['candidatos']->pluck('id')->all()];
            }

            return ['id' => $l->id, 'nombre' => $l->nombre, 'evento' => $l->datos_comunes['evento'] ?? null, 'fecha' => $l->datos_comunes['fecha'] ?? null, 'participantes' => $l->participantes_count, 'propuesta' => $propuesta];
        });

        $r = $historico->resumen();

        return Inertia::render('CredentialFlow/Eventos/Index', [
            'eventos' => $eventos,
            'sin_evento' => $sinEvento,
            'historico' => ['eventos' => $r['eventos'], 'certificados' => $r['certificados']],
        ]);
    }

    public function show(Request $request, EventoCertificacion $evento): Response
    {
        abort_unless($evento->origen === EventoCertificacion::ORIGEN_CREDENTIAL_FLOW, 404);
        $estado = in_array($request->query('estado'), [Emision::EMITIDA, Emision::REVOCADA], true) ? $request->query('estado') : Emision::EMITIDA;

        $bases = Lote::query()->where('evento_id', $evento->id)->with('plantilla:id,nombre')->orderBy('id')->get()
            ->map(function (Lote $l) {
                $personas = DB::table('cf_participantes')->where('lote_id', $l->id)->whereNull('deleted_at')->select('es_control_qa', DB::raw('count(*) n'))->groupBy('es_control_qa')->pluck('n', 'es_control_qa');
                $por = DB::table('cf_emisiones as e')->join('cf_participantes as p', 'p.id', '=', 'e.participante_id')->where('e.lote_id', $l->id)
                    ->select('p.es_control_qa', 'e.estado', DB::raw('count(*) n'))->groupBy('p.es_control_qa', 'e.estado')->get();
                $n = fn (int $qa, string $estado) => (int) ($por->first(fn ($f) => (int) $f->es_control_qa === $qa && $f->estado === $estado)->n ?? 0);

                return ['id' => $l->id, 'nombre' => $l->nombre, 'plantilla' => $l->plantilla?->nombre,
                    'reales' => (int) ($personas[0] ?? 0), 'qa' => (int) ($personas[1] ?? 0),
                    'vigentes' => $n(0, Emision::EMITIDA), 'revocadas' => $n(0, Emision::REVOCADA), 'qa_vigentes' => $n(1, Emision::EMITIDA)];
            });

        // Emisiones de participantes REALES (los participantes de control QA nunca se mezclan en esta lista, estén en la base que estén).
        $emisiones = ServicioEventos::emisiones($evento->id, false)->where('cf_emisiones.estado', $estado)->with('participante:id,nombre_completo')
            ->orderBy('cf_emisiones.lote_id')->orderBy('cf_emisiones.id')->paginate(25)->withQueryString()
            ->through(fn (Emision $e) => ['id' => $e->id, 'codigo' => $e->codigo, 'estado' => $e->estado, 'version' => $e->version, 'lote_id' => $e->lote_id, 'participante' => $e->participante?->nombre_completo,
                'emitido_at' => $e->emitido_at?->toIso8601String(), 'revocado_at' => $e->revocado_at?->toIso8601String()]);

        $disponibles = Lote::query()->whereNull('evento_id')->latest()->limit(100)->get(['id', 'nombre'])->map(fn ($l) => ['id' => $l->id, 'nombre' => $l->nombre]);

        return Inertia::render('CredentialFlow/Eventos/Show', [
            'evento' => ['id' => $evento->id, 'nombre' => $evento->nombre, 'anio' => $evento->anio, 'fecha_texto' => $evento->fecha_texto] + $this->servicio->resumen($evento),
            'bases' => $bases, 'emisiones' => $emisiones, 'estado' => $estado, 'disponibles' => $disponibles,
        ]);
    }

    /**
     * Asocia la base a su evento. Sin elección explícita solo actúa si NO hay ambigüedad (un único evento con el mismo nombre y año, o ninguno). Si la hay,
     * exige `evento_id` (elegir uno existente) o `crear_nuevo` (confirmar que es un evento nuevo). Repetirlo no duplica nada.
     */
    public function desdeBase(Request $request, Lote $lote): RedirectResponse
    {
        $datos = $request->validate(['evento_id' => ['nullable', 'integer'], 'crear_nuevo' => ['nullable', 'boolean']]);
        try {
            $evento = $this->servicio->asociarDesdeDatosDeLaBase($lote, isset($datos['evento_id']) ? (int) $datos['evento_id'] : null, (bool) ($datos['crear_nuevo'] ?? false));
        } catch (AsociacionAmbiguaException|InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('credential-flow.eventos.show', $evento)->with('success', 'La base quedó asociada al evento «'.$evento->nombre.'».');
    }

    /** Asocia la base a un evento nativo existente, o la desasocia (`evento_id` vacío). */
    public function asociar(Request $request, Lote $lote): RedirectResponse
    {
        $datos = $request->validate(['evento_id' => ['nullable', 'integer']]);
        if (($datos['evento_id'] ?? null) === null) {
            $this->servicio->desasociar($lote);

            return back()->with('success', 'La base ya no está asociada a ningún evento.');
        }
        $evento = EventoCertificacion::query()->findOrFail($datos['evento_id']);
        try {
            $this->servicio->asociar($lote, $evento);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'La base quedó asociada al evento.');
    }

    public function zip(Request $request, EventoCertificacion $evento): BinaryFileResponse|RedirectResponse
    {
        abort_unless($evento->origen === EventoCertificacion::ORIGEN_CREDENTIAL_FLOW, 404);
        try {
            return $this->zip->paraEvento($evento, $request->boolean('incluir_qa'));
        } catch (EmisionException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
