<?php

namespace App\Support;

use App\Models\PodcastEpisodio;

/**
 * Reglas de visibilidad pública del podcast, compartidas por el detalle y las interacciones.
 */
final class PodcastElegibilidad
{
    /**
     * Episodio activo, no eliminado, cuya temporada está activa y no eliminada. Null si no es elegible.
     * El soft delete de ambos queda excluido por los scopes de los modelos.
     */
    public static function episodioPorSlug(string $slug): ?PodcastEpisodio
    {
        $activoId = EstadoResolver::activo();

        return PodcastEpisodio::with('temporada')
            ->where('slug', $slug)
            ->where('estado_id', $activoId)
            ->whereHas('temporada', fn ($q) => $q->where('estado_id', $activoId))
            ->first();
    }
}
