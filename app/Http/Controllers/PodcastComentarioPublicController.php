<?php

namespace App\Http\Controllers;

use App\Models\PodcastComentario;
use App\Models\PodcastEpisodio;
use App\Support\PodcastComentariosPublicos;
use App\Support\PodcastElegibilidad;
use App\Support\PodcastVisitante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Comentarios de visitantes anónimos sobre episodios públicos.
 * Todo comentario nuevo queda pendiente; solo los aprobados se listan.
 */
class PodcastComentarioPublicController extends Controller
{
    private const VENTANA_DUPLICADO_MINUTOS = 10;

    // Campo señuelo: los formularios reales lo dejan vacío; los bots suelen rellenarlo.
    private const HONEYPOT = 'sitio_web';

    public function index(Request $request, string $slug): JsonResponse
    {
        $episodio = PodcastElegibilidad::episodioPorSlug($slug);
        abort_unless($episodio, 404);

        $pagina = (int) $request->query('pagina', 1);

        return response()->json(PodcastComentariosPublicos::pagina($episodio, $pagina));
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $episodio = PodcastElegibilidad::episodioPorSlug($slug);
        abort_unless($episodio, 404);

        $validado = $request->validate($this->reglas(), $this->mensajes());

        $uuid = PodcastVisitante::uuidDesde($request);
        $cookieNueva = null;

        if ($uuid === null) {
            $uuid = PodcastVisitante::nuevoUuid();
            $cookieNueva = PodcastVisitante::cookie($uuid);
        }

        $respuesta = response()->json([
            'mensaje' => 'Gracias por tu comentario. Se publicará cuando sea revisado por nuestro equipo.',
        ], 201);

        // Honeypot relleno: se responde igual que un envío correcto, pero no se guarda nada.
        if (filled($request->input(self::HONEYPOT))) {
            return $cookieNueva ? $respuesta->withCookie($cookieNueva) : $respuesta;
        }

        $contenido = $this->normalizar($validado['contenido']);
        $ipHash = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));

        if ($this->esDuplicado($episodio, $ipHash, $contenido)) {
            throw ValidationException::withMessages([
                'contenido' => 'Ya recibimos este mismo comentario hace un momento. Está pendiente de revisión.',
            ]);
        }

        PodcastComentario::create([
            'episodio_id' => $episodio->id,
            'nombre' => $this->normalizar($validado['nombre']),
            'correo' => filled($validado['correo'] ?? null) ? mb_strtolower(trim($validado['correo'])) : null,
            'contenido' => $contenido,
            'estado_moderacion' => PodcastComentario::PENDIENTE,
            'ip_hash' => $ipHash,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return $cookieNueva ? $respuesta->withCookie($cookieNueva) : $respuesta;
    }

    private function reglas(): array
    {
        return [
            'nombre' => ['required', 'string', 'min:2', 'max:80'],
            'correo' => ['nullable', 'string', 'email', 'max:150'],
            'contenido' => ['required', 'string', 'min:10', 'max:1000'],
            self::HONEYPOT => ['nullable', 'string', 'max:255'],
        ];
    }

    private function mensajes(): array
    {
        return [
            'nombre.required' => 'Escribe tu nombre.',
            'nombre.min' => 'Tu nombre debe tener al menos 2 caracteres.',
            'nombre.max' => 'Tu nombre no puede superar los 80 caracteres.',
            'correo.email' => 'Revisa el correo: no parece una dirección válida.',
            'correo.max' => 'El correo no puede superar los 150 caracteres.',
            'contenido.required' => 'Escribe tu comentario.',
            'contenido.min' => 'Tu comentario debe tener al menos 10 caracteres.',
            'contenido.max' => 'Tu comentario no puede superar los 1000 caracteres.',
        ];
    }

    /**
     * Texto plano: sin etiquetas, espacios repetidos colapsados, saltos de línea conservados (máximo dos seguidos).
     */
    private function normalizar(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", strip_tags($texto));
        $texto = preg_replace('/[ \t]+/u', ' ', $texto);
        $texto = preg_replace('/[ \t]*\n[ \t]*/u', "\n", $texto);
        $texto = preg_replace('/\n{3,}/u', "\n\n", $texto);

        return trim($texto);
    }

    /**
     * Mismo contenido, mismo origen y mismo episodio en los últimos minutos, en cualquier estado de moderación.
     */
    private function esDuplicado(PodcastEpisodio $episodio, string $ipHash, string $contenido): bool
    {
        return PodcastComentario::where('episodio_id', $episodio->id)
            ->where('ip_hash', $ipHash)
            ->where('contenido', $contenido)
            ->where('created_at', '>=', now()->subMinutes(self::VENTANA_DUPLICADO_MINUTOS))
            ->exists();
    }
}
