<?php

namespace App\Support;

use App\Models\PodcastComentario;
use App\Models\PodcastEpisodio;

/**
 * Comentarios visibles al público: solo aprobados y no eliminados, en páginas acotadas.
 * El DTO nunca incluye correo, ip_hash, user_agent ni datos de moderación.
 */
final class PodcastComentariosPublicos
{
    public const POR_PAGINA = 10;

    public static function pagina(PodcastEpisodio $episodio, int $pagina = 1): array
    {
        $pagina = max(1, $pagina);

        $consulta = PodcastComentario::where('episodio_id', $episodio->id)
            ->where('estado_moderacion', PodcastComentario::APROBADO)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $total = (clone $consulta)->count();

        $comentarios = $consulta
            ->skip(($pagina - 1) * self::POR_PAGINA)
            ->take(self::POR_PAGINA + 1) // uno extra solo para saber si hay más
            ->get();

        $hayMas = $comentarios->count() > self::POR_PAGINA;

        return [
            'total' => $total,
            'pagina' => $pagina,
            'hay_mas' => $hayMas,
            'comentarios' => $comentarios->take(self::POR_PAGINA)
                ->map(fn (PodcastComentario $c) => self::dto($c))
                ->values()
                ->all(),
        ];
    }

    public static function dto(PodcastComentario $c): array
    {
        return [
            'id' => $c->id,
            'nombre' => $c->nombre,
            'fecha' => $c->created_at?->toIso8601String(),
            'contenido' => $c->contenido,
        ];
    }
}
