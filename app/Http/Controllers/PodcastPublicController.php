<?php

namespace App\Http\Controllers;

use App\Models\PodcastEpisodio;
use App\Models\PodcastTemporada;
use App\Support\EstadoResolver;
use App\Support\YouTubeUrl;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PodcastPublicController extends Controller
{
    private const CANAL_YOUTUBE = 'https://www.youtube.com/FYCConsultores';

    public function index(): Response
    {
        $activoId = EstadoResolver::activo();

        // Solo temporadas activas; el soft delete queda excluido por el scope del modelo.
        $temporadas = PodcastTemporada::where('estado_id', $activoId)
            ->orderBy('numero')
            ->get();

        // Solo episodios activos, no eliminados, de una temporada elegible.
        // Orden "más reciente primero": fecha de publicación, luego temporada y número.
        $episodios = PodcastEpisodio::with('temporada')
            ->where('estado_id', $activoId)
            ->whereIn('temporada_id', $temporadas->pluck('id'))
            ->get()
            ->sortByDesc(fn ($e) => sprintf(
                '%s|%05d|%05d',
                $e->fecha_publicacion?->format('Y-m-d') ?? '0000-00-00',
                $e->temporada->numero,
                $e->numero
            ))
            ->values();

        // Destacado real si es elegible; si no, el más reciente. Puede no haber ninguno.
        $destacado = $episodios->firstWhere('destacado', true) ?? $episodios->first();

        $conteoPorTemporada = $episodios->countBy('temporada_id');

        $temporadaInicial = $destacado?->temporada->numero
            ?? $temporadas->filter(fn ($t) => ($conteoPorTemporada[$t->id] ?? 0) > 0)->last()?->numero
            ?? $temporadas->last()?->numero;

        return Inertia::render('Home/Podcast', [
            'temporadas' => $temporadas->map(fn ($t) => [
                'numero'          => $t->numero,
                'titulo'          => $t->titulo,
                'descripcion'     => $t->descripcion,
                'episodios_count' => $conteoPorTemporada[$t->id] ?? 0,
                'disponible'      => ($conteoPorTemporada[$t->id] ?? 0) > 0,
            ])->values(),
            'episodios'        => $episodios->map(fn ($e) => $this->aDto($e))->values(),
            'destacadoId'      => $destacado?->id,
            'temporadaInicial' => $temporadaInicial,
            'canalUrl'         => self::CANAL_YOUTUBE,
        ]);
    }

    private function aDto(PodcastEpisodio $e): array
    {
        return [
            'id'                => $e->id,
            'temporada'         => $e->temporada->numero,
            'numero'            => $e->numero,
            'slug'              => $e->slug,
            'titulo'            => $e->titulo,
            'descripcion'       => $e->descripcion,
            'invitado'          => $e->invitado_nombre,
            'cargo'             => $e->invitado_cargo,
            'invitado_foto'     => $e->invitado_foto ? Storage::url($e->invitado_foto) : null,
            'fecha'             => $e->fecha_publicacion?->format('Y-m-d'),
            'duracion'          => $this->formatoDuracion($e->duracion_segundos),
            // Miniatura personalizada si existe; si no, la de YouTube (no se descarga al servidor).
            'miniatura'         => $e->imagen_miniatura
                ? Storage::url($e->imagen_miniatura)
                : YouTubeUrl::thumbnailUrl($e->youtube_video_id),
            'youtube_watch_url' => YouTubeUrl::watchUrl($e->youtube_video_id),
            'destacado'         => (bool) $e->destacado,
        ];
    }

    private function formatoDuracion(?int $segundos): ?string
    {
        if (!$segundos || $segundos <= 0) {
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
