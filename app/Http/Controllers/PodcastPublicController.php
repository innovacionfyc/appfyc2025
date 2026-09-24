<?php

namespace App\Http\Controllers;

use App\Models\PodcastEpisodio;
use App\Models\PodcastReaccion;
use App\Models\PodcastTemporada;
use App\Support\EstadoResolver;
use App\Support\PodcastComentariosPublicos;
use App\Support\PodcastElegibilidad;
use App\Support\PodcastVisitante;
use App\Support\YouTubeUrl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PodcastPublicController extends Controller
{
    private const CANAL_YOUTUBE = 'https://www.youtube.com/FYCConsultores';

    private const RELACIONADOS_MAX = 3;

    private const SEO_SITE_NAME = 'F&C Consultores';

    private const SEO_PODCAST = 'Íntimamente Hablando';

    private const SEO_DESCRIPCION_LISTADO = 'Íntimamente Hablando es el podcast de F&C Consultores: conversaciones pausadas con las personas que han construido lo público en Colombia, su oficio, sus decisiones y lo que aprendieron en el camino.';

    private const SEO_IMAGEN_PODCAST = 'images/podcast/intimamente-hablando.png';

    private const SEO_DESCRIPCION_MAX = 160;

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
        ])->withViewData('seo', $this->seoListado());
    }

    public function show(Request $request, string $slug): Response
    {
        // Mismas reglas que el listado: episodio activo, no eliminado, de una temporada elegible.
        // Cualquier otro caso (inexistente, borrador, eliminado, temporada no elegible) es 404.
        $episodio = PodcastElegibilidad::episodioPorSlug($slug);

        abort_unless($episodio, 404);

        // Relacionados: primero los de la misma temporada, luego el resto, siempre del más reciente al más antiguo.
        $relacionados = $this->episodiosElegibles($this->temporadasElegibles())
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
            'reacciones' => $this->reacciones($request, $episodio),
            // Solo la primera página de comentarios aprobados; el resto se pide por JSON.
            'comentarios' => [
                'inicial' => PodcastComentariosPublicos::pagina($episodio, 1),
                'listar_url' => route('podcast.comentarios.index', $episodio->slug),
                'enviar_url' => route('podcast.comentarios.store', $episodio->slug),
            ],
            'listadoUrl' => route('podcast.index'),
            'canalUrl' => self::CANAL_YOUTUBE,
        ])->withViewData('seo', $this->seoEpisodio($episodio));
    }

    /**
     * Conteo de "me gusta" y si este visitante (cookie) ya lo dio. No expone el fingerprint.
     * Sin cookie no se crea ninguna: solo aparece cuando el visitante interactúa.
     */
    private function reacciones(Request $request, PodcastEpisodio $episodio): array
    {
        $base = PodcastReaccion::where('episodio_id', $episodio->id)
            ->where('tipo', PodcastReaccion::ME_GUSTA);

        $uuid = PodcastVisitante::uuidDesde($request);

        return [
            'total' => (clone $base)->count(),
            'activo' => $uuid !== null
                && (clone $base)->where('fingerprint', PodcastVisitante::fingerprint($uuid))->exists(),
            'url' => route('podcast.reaccion', $episodio->slug),
        ];
    }

    /**
     * Etiquetas SEO/OpenGraph del listado: datos institucionales e imagen oficial del podcast.
     * Se renderizan en app.blade.php para que existan en el HTML inicial, sin SSR.
     */
    private function seoListado(): array
    {
        return [
            'title' => 'Podcast '.self::SEO_PODCAST,
            'description' => self::SEO_DESCRIPCION_LISTADO,
            'url' => route('podcast.index'),
            'type' => 'website',
            'image' => asset(self::SEO_IMAGEN_PODCAST),
            'image_alt' => self::SEO_PODCAST.', podcast de '.self::SEO_SITE_NAME,
            'site_name' => self::SEO_SITE_NAME,
            'locale' => 'es_CO',
        ];
    }

    /**
     * Etiquetas SEO/OpenGraph del detalle a partir de los datos públicos del episodio elegible.
     * Con alternativas seguras si falta descripción o imagen; sin llamadas externas.
     */
    private function seoEpisodio(PodcastEpisodio $e): array
    {
        $descripcion = Str::limit(
            trim(preg_replace('/\s+/u', ' ', strip_tags((string) $e->descripcion))),
            self::SEO_DESCRIPCION_MAX
        );

        if ($descripcion === '') {
            $descripcion = 'Conversación con '.$e->invitado_nombre
                .($e->invitado_cargo ? ', '.$e->invitado_cargo : '')
                .' en '.self::SEO_PODCAST.', el podcast de '.self::SEO_SITE_NAME.'.';
        }

        // Miniatura personalizada (absoluta) > miniatura de YouTube por URL > imagen oficial del podcast.
        if ($e->imagen_miniatura) {
            $imagen = url(Storage::url($e->imagen_miniatura));
        } elseif (YouTubeUrl::esIdValido($e->youtube_video_id)) {
            $imagen = YouTubeUrl::thumbnailUrl($e->youtube_video_id);
        } else {
            $imagen = asset(self::SEO_IMAGEN_PODCAST);
        }

        return [
            'title' => $e->titulo.' · '.self::SEO_PODCAST,
            'description' => $descripcion,
            'url' => route('podcast.show', $e->slug),
            'type' => 'article',
            'image' => $imagen,
            'image_alt' => $e->titulo.' — '.self::SEO_PODCAST,
            'site_name' => self::SEO_SITE_NAME,
            'locale' => 'es_CO',
        ];
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
