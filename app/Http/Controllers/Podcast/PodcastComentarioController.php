<?php

namespace App\Http\Controllers\Podcast;

use App\Http\Controllers\Controller;
use App\Models\Movimiento;
use App\Models\PodcastComentario;
use App\Models\PodcastEpisodio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Moderación de comentarios del podcast. Solo los aprobados y no eliminados se muestran al público.
 */
class PodcastComentarioController extends Controller
{
    private const POR_PAGINA = 15;

    private const FILTRO_PAPELERA = 'papelera';

    private const FILTROS_ESTADO = [
        PodcastComentario::PENDIENTE,
        PodcastComentario::APROBADO,
        PodcastComentario::RECHAZADO,
        self::FILTRO_PAPELERA,
    ];

    public function index(Request $request): Response
    {
        $estado = (string) $request->query('estado', '');
        $filtros = [
            'estado' => in_array($estado, self::FILTROS_ESTADO, true) ? $estado : null,
            'episodio_id' => $request->integer('episodio_id') ?: null,
            'q' => trim((string) $request->query('q', '')) ?: null,
        ];

        $consulta = PodcastComentario::with(['episodio.temporada', 'moderador.perfilOrganizador'])
            ->when($filtros['estado'] === self::FILTRO_PAPELERA, fn ($q) => $q->onlyTrashed())
            ->when(
                $filtros['estado'] && $filtros['estado'] !== self::FILTRO_PAPELERA,
                fn ($q) => $q->where('estado_moderacion', $filtros['estado'])
            )
            ->when($filtros['episodio_id'], fn ($q, $v) => $q->where('episodio_id', $v))
            ->when($filtros['q'], fn ($q, $v) => $q->where(function ($w) use ($v) {
                $w->where('nombre', 'like', "%{$v}%")
                    ->orWhere('correo', 'like', "%{$v}%")
                    ->orWhere('contenido', 'like', "%{$v}%");
            }))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $paginado = $consulta->paginate(self::POR_PAGINA)->withQueryString();

        return Inertia::render('Podcast/Comentarios', [
            'comentarios' => [
                'data' => collect($paginado->items())->map(fn ($c) => $this->aDto($c))->values(),
                'pagina' => $paginado->currentPage(),
                'ultima' => $paginado->lastPage(),
                'total' => $paginado->total(),
                'desde' => $paginado->firstItem(),
                'hasta' => $paginado->lastItem(),
            ],
            'episodios' => PodcastEpisodio::with('temporada')
                ->get()
                ->sortBy([['temporada.numero', 'desc'], ['numero', 'desc']])
                ->values()
                ->map(fn ($e) => ['id' => $e->id, 'codigo' => $this->codigo($e), 'titulo' => $e->titulo]),
            'filtros' => $filtros,
            'stats' => $this->stats(),
        ]);
    }

    public function aprobar(PodcastComentario $comentario)
    {
        return $this->moderar($comentario, PodcastComentario::APROBADO, 'aprobó', 'aprobado');
    }

    public function rechazar(PodcastComentario $comentario)
    {
        return $this->moderar($comentario, PodcastComentario::RECHAZADO, 'rechazó', 'rechazado');
    }

    /**
     * Soft delete: desaparece del público y del listado normal; puede restaurarse desde la papelera.
     */
    public function destroy(PodcastComentario $comentario)
    {
        try {
            $comentario->delete();

            Movimiento::registrar(
                tipo: 'eliminacion',
                modulo: 'podcast',
                descripcion: "Se envió a la papelera el comentario #{$comentario->id} de {$comentario->nombre} en {$this->codigoDe($comentario)}"
            );

            return back()->with('success', "El comentario de {$comentario->nombre} pasó a la papelera y puede restaurarse.");
        } catch (\Exception $e) {
            Log::error('Error al eliminar comentario del podcast: '.$e->getMessage());

            return back()->with('error', 'Error al eliminar el comentario.');
        }
    }

    public function restore($id)
    {
        $comentario = PodcastComentario::withTrashed()->findOrFail($id);
        $comentario->restore();

        Movimiento::registrar(
            tipo: 'restauracion',
            modulo: 'podcast',
            descripcion: "Se restauró el comentario #{$comentario->id} de {$comentario->nombre} en {$this->codigoDe($comentario)} (estado: {$comentario->estado_moderacion})"
        );

        return back()->with('success', "El comentario de {$comentario->nombre} fue restaurado con su estado anterior ({$comentario->estado_moderacion}).");
    }

    private function moderar(PodcastComentario $comentario, string $estado, string $verbo, string $participio)
    {
        if ($comentario->estado_moderacion === $estado) {
            return back()->with('success', "El comentario de {$comentario->nombre} ya estaba {$participio}.");
        }

        try {
            $anterior = $comentario->estado_moderacion;

            $comentario->update([
                'estado_moderacion' => $estado,
                'moderado_por' => Auth::id(),
                'moderado_en' => now(),
            ]);

            Movimiento::registrar(
                tipo: 'actualizacion',
                modulo: 'podcast',
                descripcion: "Se {$verbo} el comentario #{$comentario->id} de {$comentario->nombre} en {$this->codigoDe($comentario)} (antes: {$anterior})"
            );

            return back()->with('success', "El comentario de {$comentario->nombre} fue {$participio}.");
        } catch (\Exception $e) {
            Log::error('Error al moderar comentario del podcast: '.$e->getMessage());

            return back()->with('error', 'Error al moderar el comentario.');
        }
    }

    private function stats(): array
    {
        $porEstado = PodcastComentario::selectRaw('estado_moderacion, count(*) as total')
            ->groupBy('estado_moderacion')
            ->pluck('total', 'estado_moderacion');

        return [
            'pendientes' => (int) ($porEstado[PodcastComentario::PENDIENTE] ?? 0),
            'aprobados' => (int) ($porEstado[PodcastComentario::APROBADO] ?? 0),
            'rechazados' => (int) ($porEstado[PodcastComentario::RECHAZADO] ?? 0),
            'papelera' => PodcastComentario::onlyTrashed()->count(),
        ];
    }

    /**
     * El admin sí ve el correo (makeVisible); ip_hash y user_agent siguen ocultos.
     */
    private function aDto(PodcastComentario $c): array
    {
        $moderador = $c->moderador?->perfilOrganizador;

        return [
            'id' => $c->id,
            'nombre' => $c->nombre,
            'correo' => $c->makeVisible('correo')->correo,
            'contenido' => $c->contenido,
            'estado_moderacion' => $c->estado_moderacion,
            'episodio' => $c->episodio ? [
                'id' => $c->episodio->id,
                'codigo' => $this->codigo($c->episodio),
                'titulo' => $c->episodio->titulo,
                'slug' => $c->episodio->slug,
            ] : null,
            'moderado_por' => $moderador
                ? trim("{$moderador->primer_nombre} {$moderador->primer_apellido}")
                : ($c->moderado_por ? "Usuario #{$c->moderado_por}" : null),
            'moderado_en' => $c->moderado_en?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
            'deleted_at' => $c->deleted_at?->toIso8601String(),
        ];
    }

    private function codigo(PodcastEpisodio $e): string
    {
        return sprintf('T%d·E%02d', $e->temporada?->numero ?? 0, $e->numero);
    }

    private function codigoDe(PodcastComentario $c): string
    {
        $episodio = $c->episodio ?? PodcastEpisodio::withTrashed()->with('temporada')->find($c->episodio_id);

        return $episodio ? $this->codigo($episodio) : "episodio #{$c->episodio_id}";
    }
}
