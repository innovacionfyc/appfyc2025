<?php

namespace App\Http\Controllers\Podcast;

use App\Http\Controllers\Controller;
use App\Models\Estado;
use App\Models\Movimiento;
use App\Models\PodcastComentario;
use App\Models\PodcastEpisodio;
use App\Models\PodcastTemporada;
use App\Support\EstadoResolver;
use App\Support\YouTubeUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PodcastEpisodioController extends Controller
{
    private const CARPETA_INVITADOS = 'podcast/invitados';
    private const CARPETA_MINIATURAS = 'podcast/miniaturas';

    private const ESTADOS_PERMITIDOS = [EstadoResolver::ACTIVO, EstadoResolver::BORRADOR, EstadoResolver::ARCHIVADO];

    public function index(Request $request): Response
    {
        $filtros = [
            'temporada_id' => $request->integer('temporada_id') ?: null,
            'estado_id'    => $request->integer('estado_id') ?: null,
            'q'            => trim((string) $request->query('q', '')) ?: null,
        ];

        $consulta = PodcastEpisodio::with(['temporada', 'estado'])
            ->withCount(['comentarios', 'comentariosAprobados', 'reacciones'])
            ->when($filtros['temporada_id'], fn ($q, $v) => $q->where('temporada_id', $v))
            ->when($filtros['estado_id'], fn ($q, $v) => $q->where('estado_id', $v))
            ->when($filtros['q'], fn ($q, $v) => $q->where(function ($w) use ($v) {
                $w->where('titulo', 'like', "%{$v}%")->orWhere('invitado_nombre', 'like', "%{$v}%");
            }));

        // Orden editorial: temporada más reciente primero y, dentro de ella, episodio más alto primero.
        $episodios = $consulta->get()
            ->sortBy([['temporada.numero', 'desc'], ['numero', 'desc']])
            ->values()
            ->map(fn ($e) => $this->aDto($e));

        $eliminados = PodcastEpisodio::onlyTrashed()
            ->with(['temporada', 'estado'])
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn ($e) => $this->aDto($e));

        $activoId = EstadoResolver::activo();
        $destacado = PodcastEpisodio::where('destacado', true)->first();

        return Inertia::render('Podcast/Episodios', [
            'episodios'  => $episodios,
            'eliminados' => $eliminados,
            'temporadas' => PodcastTemporada::orderBy('numero')->get(['id', 'numero', 'titulo', 'estado_id']),
            'estados'    => $this->estadosPermitidos(),
            'filtros'    => $filtros,
            'stats'      => [
                'total'                  => PodcastEpisodio::count(),
                'activos'                => PodcastEpisodio::where('estado_id', $activoId)->count(),
                'borradores'             => PodcastEpisodio::where('estado_id', EstadoResolver::borrador())->count(),
                'eliminados'             => $eliminados->count(),
                'destacado'              => $destacado ? $this->codigo($destacado) . ' · ' . $destacado->titulo : null,
                'comentarios_pendientes' => PodcastComentario::where('estado_moderacion', PodcastComentario::PENDIENTE)->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $archivos = [];

        try {
            $validated = $request->validate($this->reglas($request), $this->mensajes());

            $temporada = PodcastTemporada::findOrFail($validated['temporada_id']);

            $validated['youtube_video_id'] = YouTubeUrl::extraerId($validated['youtube_url']);
            $validated['destacado'] = $request->boolean('destacado');
            $validated['slug'] = $this->generarSlug($temporada->numero, $validated['numero'], $validated['titulo']);

            $archivos = $this->guardarArchivos($request);
            $validated = array_merge($validated, $archivos);

            $episodio = DB::transaction(function () use ($validated) {
                if ($validated['destacado']) {
                    $this->desmarcarDestacados();
                }

                return PodcastEpisodio::create($validated);
            });

            Movimiento::registrar(
                tipo: 'registro',
                modulo: 'podcast',
                descripcion: "Se creó el episodio {$this->codigo($episodio)} del podcast: {$episodio->titulo}"
            );

            return back()->with('success', "¡El episodio {$this->codigo($episodio)} \"{$episodio->titulo}\" se creó correctamente!");

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->borrarArchivos(array_filter($archivos));
            Log::error('Error al crear episodio del podcast: ' . $e->getMessage());
            return back()->withErrors(['general' => 'Ocurrió un error inesperado al crear el episodio.']);
        }
    }

    public function update(Request $request, PodcastEpisodio $episodio)
    {
        $archivosNuevos = [];

        try {
            $validated = $request->validate($this->reglas($request, $episodio), $this->mensajes());

            $temporada = PodcastTemporada::findOrFail($validated['temporada_id']);

            $validated['youtube_video_id'] = YouTubeUrl::extraerId($validated['youtube_url']);
            $validated['destacado'] = $request->boolean('destacado');

            // Regenerar slug solo si cambió temporada, número o título
            $cambioIdentidad = (int) $validated['temporada_id'] !== (int) $episodio->temporada_id
                || (int) $validated['numero'] !== (int) $episodio->numero
                || Str::slug($validated['titulo']) !== Str::slug($episodio->titulo);

            if ($cambioIdentidad) {
                $validated['slug'] = $this->generarSlug($temporada->numero, $validated['numero'], $validated['titulo'], $episodio->id);
            }

            // Orden seguro: guardar nuevos -> actualizar BD -> borrar anteriores
            $archivosNuevos = $this->guardarArchivos($request);
            $archivosAnteriores = [];
            foreach (['invitado_foto', 'imagen_miniatura'] as $campo) {
                if (isset($archivosNuevos[$campo])) {
                    $archivosAnteriores[] = $episodio->{$campo};
                    $validated[$campo] = $archivosNuevos[$campo];
                } else {
                    unset($validated[$campo]);
                }
            }

            DB::transaction(function () use ($episodio, $validated) {
                if ($validated['destacado']) {
                    $this->desmarcarDestacados($episodio->id);
                }

                $episodio->update($validated);
            });

            $this->borrarArchivos(array_filter($archivosAnteriores));

            Movimiento::registrar(
                tipo: 'actualizacion',
                modulo: 'podcast',
                descripcion: "Se actualizó el episodio {$this->codigo($episodio)} del podcast: {$episodio->titulo}"
            );

            return back()->with('success', "¡El episodio {$this->codigo($episodio)} \"{$episodio->titulo}\" se actualizó correctamente!");

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->borrarArchivos(array_filter($archivosNuevos));
            Log::error('Error al actualizar episodio del podcast: ' . $e->getMessage());
            return back()->withErrors(['general' => 'Ocurrió un error inesperado al actualizar el episodio.']);
        }
    }

    /**
     * Soft delete. Comentarios, reacciones y archivos se conservan para que restore() lo devuelva completo.
     */
    public function destroy(PodcastEpisodio $episodio)
    {
        try {
            $episodio->delete();

            Movimiento::registrar(
                tipo: 'eliminacion',
                modulo: 'podcast',
                descripcion: "Se eliminó el episodio {$this->codigo($episodio)} del podcast: {$episodio->titulo}"
            );

            return back()->with('success', "El episodio {$this->codigo($episodio)} pasó a la papelera y puede restaurarse.");

        } catch (\Exception $e) {
            Log::error('Error al eliminar episodio del podcast: ' . $e->getMessage());
            return back()->with('error', 'Error al eliminar el episodio.');
        }
    }

    public function restore($id)
    {
        $episodio = PodcastEpisodio::withTrashed()->findOrFail($id);

        DB::transaction(function () use ($episodio) {
            // Mantener un único destacado: si ya hay otro activo, el restaurado vuelve sin la marca
            if ($episodio->destacado && PodcastEpisodio::where('destacado', true)->where('id', '!=', $episodio->id)->exists()) {
                $episodio->destacado = false;
            }

            $episodio->restore();
        });

        Movimiento::registrar(
            tipo: 'restauracion',
            modulo: 'podcast',
            descripcion: "Se restauró el episodio {$this->codigo($episodio)} del podcast: {$episodio->titulo}"
        );

        return back()->with('success', "El episodio {$this->codigo($episodio)} fue restaurado correctamente.");
    }

    /**
     * Parsea la URL pegada por el admin para el preview del modal. Sin llamadas externas.
     */
    public function validarYoutube(Request $request): JsonResponse
    {
        $url = (string) $request->input('url', '');
        $videoId = YouTubeUrl::extraerId($url);

        if (!$videoId) {
            return response()->json([
                'valido'  => false,
                'mensaje' => 'No se reconoce un video de YouTube válido en esa URL.',
            ], 422);
        }

        return response()->json([
            'valido'        => true,
            'video_id'      => $videoId,
            'watch_url'     => YouTubeUrl::watchUrl($videoId),
            'embed_url'     => YouTubeUrl::embedUrl($videoId),
            'thumbnail_url' => YouTubeUrl::thumbnailUrl($videoId),
        ]);
    }

    private function reglas(Request $request, ?PodcastEpisodio $actual = null): array
    {
        // El unique de BD incluye filas en papelera, así que la validación tampoco las ignora.
        $numeroUnico = Rule::unique('podcast_episodios', 'numero')
            ->where('temporada_id', $request->input('temporada_id'));
        if ($actual) {
            $numeroUnico->ignore($actual->id);
        }

        return [
            'temporada_id'      => ['required', 'integer', Rule::exists('podcast_temporadas', 'id')->whereNull('deleted_at')],
            'numero'            => ['required', 'integer', 'min:1', 'max:65535', $numeroUnico],
            'titulo'            => ['required', 'string', 'max:220'],
            'invitado_nombre'   => ['required', 'string', 'max:150'],
            'invitado_cargo'    => ['nullable', 'string', 'max:200'],
            'invitado_foto'     => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'descripcion'       => ['required', 'string', 'max:5000'],
            'fecha_publicacion' => ['nullable', 'date'],
            'youtube_url'       => ['required', 'string', 'max:500', function ($attr, $value, $fail) {
                if (!YouTubeUrl::extraerId((string) $value)) {
                    $fail('No se reconoce un video de YouTube válido en la URL (usa watch?v=, youtu.be, shorts o live).');
                }
            }],
            'imagen_miniatura'  => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'duracion_segundos' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'destacado'         => ['nullable', 'boolean'],
            'estado_id'         => ['required', 'integer', Rule::in(EstadoResolver::ids(self::ESTADOS_PERMITIDOS))],
        ];
    }

    private function mensajes(): array
    {
        return [
            'temporada_id.required'       => 'Selecciona la temporada del episodio.',
            'temporada_id.exists'         => 'La temporada seleccionada no existe o está en la papelera.',
            'numero.required'             => 'El número del episodio es obligatorio.',
            'numero.integer'              => 'El número del episodio debe ser un entero.',
            'numero.min'                  => 'El número del episodio debe ser mayor o igual a 1.',
            'numero.unique'               => 'Ya existe un episodio con ese número en esta temporada (revisa también la papelera).',
            'titulo.required'             => 'El título del episodio es obligatorio.',
            'titulo.max'                  => 'El título no puede superar los 220 caracteres.',
            'invitado_nombre.required'    => 'El nombre del invitado es obligatorio.',
            'invitado_nombre.max'         => 'El nombre del invitado no puede superar los 150 caracteres.',
            'invitado_cargo.max'          => 'El cargo del invitado no puede superar los 200 caracteres.',
            'invitado_foto.uploaded'      => 'La foto del invitado no pudo subirse. Verifica el tamaño y vuelve a intentarlo.',
            'invitado_foto.image'         => 'La foto del invitado debe ser una imagen válida.',
            'invitado_foto.mimes'         => 'La foto del invitado debe estar en formato JPG, PNG o WEBP.',
            'invitado_foto.max'           => 'La foto del invitado no puede superar los 4 MB.',
            'descripcion.required'        => 'La descripción del episodio es obligatoria.',
            'descripcion.max'             => 'La descripción no puede superar los 5000 caracteres.',
            'fecha_publicacion.date'      => 'La fecha de publicación no es válida.',
            'youtube_url.required'        => 'La URL del video de YouTube es obligatoria.',
            'youtube_url.max'             => 'La URL del video es demasiado larga.',
            'imagen_miniatura.uploaded'   => 'La miniatura no pudo subirse. Verifica el tamaño y vuelve a intentarlo.',
            'imagen_miniatura.image'      => 'La miniatura debe ser una imagen válida.',
            'imagen_miniatura.mimes'      => 'La miniatura debe estar en formato JPG, PNG o WEBP.',
            'imagen_miniatura.max'        => 'La miniatura no puede superar los 4 MB.',
            'duracion_segundos.integer'   => 'La duración debe expresarse en segundos (entero).',
            'duracion_segundos.min'       => 'La duración no puede ser negativa.',
            'duracion_segundos.max'       => 'La duración no puede superar las 24 horas.',
            'destacado.boolean'           => 'El campo destacado no es válido.',
            'estado_id.required'          => 'El estado del episodio es obligatorio.',
            'estado_id.in'                => 'El estado seleccionado no es válido para un episodio.',
        ];
    }

    /** @return array<string, string> rutas guardadas, solo de los archivos presentes en la request */
    private function guardarArchivos(Request $request): array
    {
        $guardados = [];

        if ($request->hasFile('invitado_foto')) {
            $guardados['invitado_foto'] = $request->file('invitado_foto')->store(self::CARPETA_INVITADOS, 'public');
        }
        if ($request->hasFile('imagen_miniatura')) {
            $guardados['imagen_miniatura'] = $request->file('imagen_miniatura')->store(self::CARPETA_MINIATURAS, 'public');
        }

        return $guardados;
    }

    private function borrarArchivos(array $rutas): void
    {
        if ($rutas) {
            Storage::disk('public')->delete(array_values($rutas));
        }
    }

    // Regla de negocio: un único episodio destacado en todo el podcast (incluye papelera para no dejar marcas huérfanas).
    private function desmarcarDestacados(?int $excepto = null): void
    {
        PodcastEpisodio::withTrashed()
            ->where('destacado', true)
            ->when($excepto, fn ($q) => $q->where('id', '!=', $excepto))
            ->update(['destacado' => false]);
    }

    private function estadosPermitidos()
    {
        return Estado::whereIn('tipo_estado', self::ESTADOS_PERMITIDOS)
            ->orderBy('id')
            ->get(['id', 'tipo_estado']);
    }

    // Slug legible y estable: t{temporada}-e{numero:02}-{titulo}; sufijo numérico si colisiona (incluye papelera).
    private function generarSlug(int $numeroTemporada, int $numeroEpisodio, string $titulo, ?int $excludeId = null): string
    {
        $base = sprintf('t%d-e%02d-%s', $numeroTemporada, $numeroEpisodio, Str::limit(Str::slug($titulo), 150, ''));
        $base = rtrim($base, '-');
        $slug = $base;
        $n = 2;

        while ($this->slugExiste($slug, $excludeId)) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    private function slugExiste(string $slug, ?int $excludeId): bool
    {
        return PodcastEpisodio::withTrashed()
            ->where('slug', $slug)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }

    private function codigo(PodcastEpisodio $e): string
    {
        $numeroTemporada = $e->temporada?->numero ?? PodcastTemporada::withTrashed()->find($e->temporada_id)?->numero ?? '?';

        return sprintf('T%s·E%02d', $numeroTemporada, $e->numero);
    }

    private function aDto(PodcastEpisodio $e): array
    {
        return [
            'id'                        => $e->id,
            'temporada_id'              => $e->temporada_id,
            'temporada'                 => $e->temporada ? [
                'id'     => $e->temporada->id,
                'numero' => $e->temporada->numero,
                'titulo' => $e->temporada->titulo,
            ] : null,
            'codigo'                    => $this->codigo($e),
            'numero'                    => $e->numero,
            'titulo'                    => $e->titulo,
            'slug'                      => $e->slug,
            'invitado_nombre'           => $e->invitado_nombre,
            'invitado_cargo'            => $e->invitado_cargo,
            'invitado_foto'             => $e->invitado_foto,
            'invitado_foto_url'         => $e->invitado_foto ? Storage::url($e->invitado_foto) : null,
            'descripcion'               => $e->descripcion,
            'fecha_publicacion'         => $e->fecha_publicacion?->format('Y-m-d'),
            'youtube_video_id'          => $e->youtube_video_id,
            'youtube_url'               => $e->youtube_url,
            'youtube_watch_url'         => YouTubeUrl::watchUrl($e->youtube_video_id),
            'youtube_embed_url'         => YouTubeUrl::embedUrl($e->youtube_video_id),
            'imagen_miniatura'          => $e->imagen_miniatura,
            // Miniatura personalizada si existe; si no, la de YouTube (no se descarga al servidor)
            'miniatura_url'             => $e->imagen_miniatura
                ? Storage::url($e->imagen_miniatura)
                : YouTubeUrl::thumbnailUrl($e->youtube_video_id),
            'duracion_segundos'         => $e->duracion_segundos,
            'destacado'                 => (bool) $e->destacado,
            'estado_id'                 => $e->estado_id,
            'estado'                    => $e->estado?->tipo_estado,
            'comentarios_count'         => $e->comentarios_count ?? 0,
            'comentarios_aprobados_count' => $e->comentarios_aprobados_count ?? 0,
            'reacciones_count'          => $e->reacciones_count ?? 0,
            'created_at'                => $e->created_at?->toDateTimeString(),
            'deleted_at'                => $e->deleted_at?->toDateTimeString(),
        ];
    }
}
