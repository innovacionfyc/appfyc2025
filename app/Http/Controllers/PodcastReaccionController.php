<?php

namespace App\Http\Controllers;

use App\Models\PodcastEpisodio;
use App\Models\PodcastReaccion;
use App\Support\PodcastElegibilidad;
use App\Support\PodcastVisitante;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Me gusta" de visitantes anónimos sobre episodios públicos.
 *
 * El cliente declara la intención (dar o quitar) en lugar de alternar: así los clics repetidos
 * y las solicitudes simultáneas son idempotentes y el índice único de la tabla nunca produce un 500.
 */
class PodcastReaccionController extends Controller
{
    public function store(Request $request, string $slug): JsonResponse
    {
        $episodio = PodcastElegibilidad::episodioPorSlug($slug);
        abort_unless($episodio, 404);

        $validado = $request->validate([
            'accion' => ['required', 'in:dar,quitar'],
        ]);

        $uuid = PodcastVisitante::uuidDesde($request);
        $cookieNueva = null;

        if ($uuid === null) {
            $uuid = PodcastVisitante::nuevoUuid();
            $cookieNueva = PodcastVisitante::cookie($uuid);
        }

        $fingerprint = PodcastVisitante::fingerprint($uuid);

        if ($validado['accion'] === 'dar') {
            $this->dar($episodio, $fingerprint);
        } else {
            $this->quitar($episodio, $fingerprint);
        }

        $respuesta = response()->json($this->estado($episodio, $fingerprint));

        return $cookieNueva ? $respuesta->withCookie($cookieNueva) : $respuesta;
    }

    private function dar(PodcastEpisodio $episodio, string $fingerprint): void
    {
        try {
            PodcastReaccion::firstOrCreate([
                'episodio_id' => $episodio->id,
                'tipo' => PodcastReaccion::ME_GUSTA,
                'fingerprint' => $fingerprint,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otra solicitud simultánea del mismo visitante ya creó la fila: el resultado es el mismo.
        }
    }

    private function quitar(PodcastEpisodio $episodio, string $fingerprint): void
    {
        PodcastReaccion::where('episodio_id', $episodio->id)
            ->where('tipo', PodcastReaccion::ME_GUSTA)
            ->where('fingerprint', $fingerprint)
            ->delete();
    }

    /**
     * Estado real leído de la base de datos tras la operación. Nunca incluye el fingerprint.
     */
    private function estado(PodcastEpisodio $episodio, string $fingerprint): array
    {
        $base = PodcastReaccion::where('episodio_id', $episodio->id)
            ->where('tipo', PodcastReaccion::ME_GUSTA);

        return [
            'total' => (clone $base)->count(),
            'activo' => (clone $base)->where('fingerprint', $fingerprint)->exists(),
        ];
    }
}
