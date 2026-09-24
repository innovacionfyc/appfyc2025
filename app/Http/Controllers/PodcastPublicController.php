<?php

namespace App\Http\Controllers;

use App\Models\PodcastEpisodio;
use App\Models\PodcastTemporada;
use App\Support\EstadoResolver;
use App\Support\YouTubeUrl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PodcastPublicController extends Controller
{
    private const CANAL_YOUTUBE = 'https://www.youtube.com/FYCConsultores';

    private const RELACIONADOS_MAX = 3;

    public function index(): Response
    {
        $temporadas = $this->temporadasElegibles();
        $episodios = $this->episodiosElegibles($temporadas);

        // Destacado real si es elegible; si no, el más reciente. Puede no haber ninguno.
        $destacado = $episodios->firstWhere('destacado', true) ?? $episodios->first();

        $conteoPorTemporada = $episodios->countBy('temporada_id');

        $temporadaInicial = $destacado?->temporada->numero
            ?? $temporadas->filter(fn ($t) => ($conteoPorTemporada[$t->id] ?? 0) > 0)->last()?->numero
            ?? $temporadas->last()?->numero;

        return Inertia::render('Home/Podcast', [
            'temporadas' => $temporadas->map(fn ($t) => [
                'numero' => $t->numero,
                'titulo' => $t->titulo,
                'descripcion' => $t->descripcion,
                'episodios_count' => $conteoPorTemporada[$t->id] ?? 0,
                'disponible' => ($conteoPorTemporada[$t->id] ?? 0) > 0,
            ])->values(),
            'episodios' => $episodios->map(fn ($e) => $this->aDto($e))->values(),
            'destacadoId' => $destacado?->id,
            'temporadaInicial' => $temporadaInicial,
            'canalUrl' => self::CANAL_YOUTUBE,
        ]);
    }

    public function show(string $slug): Response
    {
        $temporadas = $this->temporadasElegibles();

        // Mismas reglas que el listado: episodio activo, no eliminado, de una temporada elegible.
        // Cualquier otro caso (inexistente, borrador, eliminado, temporada no elegible) es 404.
        $episodio = PodcastEpisodio::with('temporada')
            ->where('slug', $slug)
            ->where('estado_id', EstadoResolver::activo())
            ->whereIn('temporada_id', $temporadas->pluck('id'))
            ->first();

        abort_unless($episodio, 404);

        // Relacionados: primero los de la misma temporada, luego el resto, siempre del más reciente al más antiguo.
        $relacionados = $this->episodiosElegibles($temporadas)
            ->reject(fn ($e) => $e->id === $episodio->id)
            ->sortBy(fn ($e) => $e->temporada_id === $episodio->temporada_id ? 0 : 1, SORT_NUMERIC, false)
            ->take(self::RELACIONADOS_MAX)
            ->values();

        return Inertia::render('Home/PodcastEpisodio', [
            'episodio' => array_merge($this->aDto($episodio), [
                'temporada_titulo' => $episodio->temporada->titulo,
                // Reproductor en modo privacidad mejorada; null si el ID no permite construirlo.
                'embed_url' => YouTubeUrl::esIdValido($episodio->youtube_video_id)
                    ? YouTubeUrl::embedUrl($episodio->youtube_video_id)
                    : null,
            ]),
            'relacionados' => $relacionados->map(fn ($e) => $this->aDto($e))->values(),
            'listadoUrl' => route('podcast.index'),
            'canalUrl' => self::CANAL_YOUTUBE,
        ]);
    }

    /**
     * Temporadas activas, no eliminadas (el soft delete queda excluido por el scope del modelo).
     */
    private function temporadasElegibles(): Collection
    {
        return PodcastTemporada::where('estado_id', EstadoResolver::activo())
            ->orderBy('numero')
            ->get();
    }

    /**
     * Episodios activos, no eliminados, de una temporada elegible.
     * Orden "más reciente primero": fecha de publicación, luego temporada y número.
     */
    private function episodiosElegibles(Collection $temporadas): Collection
    {
        return PodcastEpisodio::with('temporada')
            ->where('estado_id', EstadoResolver::activo())
            ->whereIn('temporada_id', $temporadas->pluck('id'))
            ->get()
            ->sortByDesc(fn ($e) => sprintf(
                '%s|%05d|%05d',
                $e->fecha_publicacion?->format('Y-m-d') ?? '0000-00-00',
                $e->temporada->numero,
                $e->numero
            ))
            ->values();
    }

    private function aDto(PodcastEpisodio $e): array
    {
        $videoValido = YouTubeUrl::esIdValido($e->youtube_video_id);

        return [
            'id' => $e->id,
            'temporada' => $e->temporada->numero,
            'numero' => $e->numero,
            'slug' => $e->slug,
            'url' => route('podcast.show', $e->slug),
            'titulo' => $e->titulo,
            'descripcion' => $e->descripcion,
            'invitado' => $e->invitado_nombre,
            'cargo' => $e->invitado_cargo,
            'invitado_foto' => $e->invitado_foto ? Storage::url($e->invitado_foto) : null,
            'fecha' => $e->fecha_publicacion?->format('Y-m-d'),
            'duracion' => $this->formatoDuracion($e->duracion_segundos),
            // Miniatura personalizada si existe; si no, la de YouTube (no se descarga al servidor).
            'miniatura' => $e->imagen_miniatura
                ? Storage::url($e->imagen_miniatura)
                : ($videoValido ? YouTubeUrl::thumbnailUrl($e->youtube_video_id) : null),
            'youtube_watch_url' => $videoValido ? YouTubeUrl::watchUrl($e->youtube_video_id) : null,
            'destacado' => (bool) $e->destacado,
        ];
    }

    private function formatoDuracion(?int $segundos): ?string
    {
        if (! $segundos || $segundos <= 0) {
            return null;
        }

        $horas = intdiv($segundos, 3600);
        $minutos = intdiv($segundos % 3600, 60);

        if ($horas > 0) {
            return sprintf('%d h %02d min', $horas, $minutos);
        }

        return $minutos > 0 ? "{$minutos} min" : '1 min';
    }
}
