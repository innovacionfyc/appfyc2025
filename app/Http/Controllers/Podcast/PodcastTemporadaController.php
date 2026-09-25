<?php

namespace App\Http\Controllers\Podcast;

use App\Http\Controllers\Controller;
use App\Models\Estado;
use App\Models\Movimiento;
use App\Models\PodcastTemporada;
use App\Support\EstadoResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PodcastTemporadaController extends Controller
{
    private const CARPETA_PORTADAS = 'podcast/portadas';

    private const ESTADOS_PERMITIDOS = [EstadoResolver::ACTIVO, EstadoResolver::BORRADOR, EstadoResolver::ARCHIVADO];

    public function index(): Response
    {
        $activoId = EstadoResolver::activo();

        $temporadas = PodcastTemporada::with('estado')
            ->withCount([
                'episodios',
                'episodios as episodios_activos_count' => fn ($q) => $q->where('estado_id', $activoId),
            ])
            ->orderBy('numero')
            ->get()
            ->map(fn ($t) => $this->aDto($t));

        $eliminadas = PodcastTemporada::onlyTrashed()
            ->with('estado')
            ->withCount('episodios')
            ->orderBy('numero')
            ->get()
            ->map(fn ($t) => $this->aDto($t));

        return Inertia::render('Podcast/Temporadas', [
            'temporadas' => $temporadas,
            'eliminadas' => $eliminadas,
            'estados' => $this->estadosPermitidos(),
            'stats' => [
                'total' => $temporadas->count(),
                'activas' => $temporadas->where('estado_id', $activoId)->count(),
                'borradores' => $temporadas->where('estado_id', EstadoResolver::borrador())->count(),
                'episodios' => (int) $temporadas->sum('episodios_count'),
                'eliminadas' => $eliminadas->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate($this->reglas(), $this->mensajes());

            $validated['slug'] = $this->generarSlug($validated['titulo']);
            $validated['imagen_portada'] = $this->guardarPortada($request);

            $temporada = PodcastTemporada::create($validated);

            Movimiento::registrar(
                tipo: 'registro',
                modulo: 'podcast',
                descripcion: "Se creó la temporada {$temporada->numero} del podcast: {$temporada->titulo}"
            );

            return back()->with('success', "¡La temporada {$temporada->numero} \"{$temporada->titulo}\" se creó correctamente!");

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error al crear temporada del podcast: '.$e->getMessage());

            return back()->withErrors(['general' => 'Ocurrió un error inesperado al crear la temporada.']);
        }
    }

    public function update(Request $request, PodcastTemporada $temporada)
    {
        try {
            $validated = $request->validate($this->reglas($temporada), $this->mensajes());

            // Regenerar slug solo si el título cambió de verdad
            if (Str::slug($validated['titulo']) !== Str::slug($temporada->titulo)) {
                $validated['slug'] = $this->generarSlug($validated['titulo'], $temporada->id);
            }

            // Orden seguro: guardar nueva portada -> actualizar BD -> borrar la anterior
            $portadaAnterior = null;
            if ($request->hasFile('imagen_portada')) {
                $portadaAnterior = $temporada->imagen_portada;
                $validated['imagen_portada'] = $this->guardarPortada($request);
            } else {
                unset($validated['imagen_portada']);
            }

            $temporada->update($validated);

            if ($portadaAnterior) {
                Storage::disk('public')->delete($portadaAnterior);
            }

            Movimiento::registrar(
                tipo: 'actualizacion',
                modulo: 'podcast',
                descripcion: "Se actualizó la temporada {$temporada->numero} del podcast: {$temporada->titulo}"
            );

            return back()->with('success', "¡La temporada {$temporada->numero} \"{$temporada->titulo}\" se actualizó correctamente!");

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error al actualizar temporada del podcast: '.$e->getMessage());

            return back()->withErrors(['general' => 'Ocurrió un error inesperado al actualizar la temporada.']);
        }
    }

    /**
     * Soft delete. Los episodios no se tocan (sin cascada manual) y la portada se conserva
     * para que restore() devuelva la temporada completa.
     */
    public function destroy(PodcastTemporada $temporada)
    {
        try {
            $temporada->delete();

            Movimiento::registrar(
                tipo: 'eliminacion',
                modulo: 'podcast',
                descripcion: "Se eliminó la temporada {$temporada->numero} del podcast: {$temporada->titulo}"
            );

            return back()->with('success', "La temporada {$temporada->numero} pasó a la papelera y puede restaurarse.");

        } catch (\Exception $e) {
            Log::error('Error al eliminar temporada del podcast: '.$e->getMessage());

            return back()->with('error', 'Error al eliminar la temporada.');
        }
    }

    public function restore($id)
    {
        $temporada = PodcastTemporada::withTrashed()->findOrFail($id);
        $temporada->restore();

        Movimiento::registrar(
            tipo: 'restauracion',
            modulo: 'podcast',
            descripcion: "Se restauró la temporada {$temporada->numero} del podcast: {$temporada->titulo}"
        );

        return back()->with('success', "La temporada {$temporada->numero} fue restaurada correctamente.");
    }

    private function reglas(?PodcastTemporada $actual = null): array
    {
        // El unique de BD incluye filas en papelera, así que la validación tampoco las ignora.
        $numeroUnico = Rule::unique('podcast_temporadas', 'numero');
        if ($actual) {
            $numeroUnico->ignore($actual->id);
        }

        return [
            'numero' => ['required', 'integer', 'min:1', 'max:65535', $numeroUnico],
            'titulo' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'imagen_portada' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'estado_id' => ['required', 'integer', Rule::in(EstadoResolver::ids(self::ESTADOS_PERMITIDOS))],
        ];
    }

    private function mensajes(): array
    {
        return [
            'numero.required' => 'El número de la temporada es obligatorio.',
            'numero.integer' => 'El número de la temporada debe ser un entero.',
            'numero.min' => 'El número de la temporada debe ser mayor o igual a 1.',
            'numero.unique' => 'Ya existe una temporada con ese número (revisa también la papelera).',
            'titulo.required' => 'El título de la temporada es obligatorio.',
            'titulo.max' => 'El título no puede superar los 200 caracteres.',
            'descripcion.max' => 'La descripción no puede superar los 2000 caracteres.',
            'imagen_portada.uploaded' => 'La portada no pudo subirse. Verifica que no supere el límite del servidor y vuelve a intentarlo.',
            'imagen_portada.image' => 'La portada debe ser una imagen válida.',
            'imagen_portada.mimes' => 'La portada debe estar en formato JPG, PNG o WEBP.',
            'imagen_portada.max' => 'La portada no puede superar los 4 MB.',
            'estado_id.required' => 'El estado de la temporada es obligatorio.',
            'estado_id.in' => 'El estado seleccionado no es válido para una temporada.',
        ];
    }

    private function guardarPortada(Request $request): ?string
    {
        return $request->hasFile('imagen_portada')
            ? $request->file('imagen_portada')->store(self::CARPETA_PORTADAS, 'public')
            : null;
    }

    private function estadosPermitidos()
    {
        return Estado::whereIn('tipo_estado', self::ESTADOS_PERMITIDOS)
            ->orderBy('id')
            ->get(['id', 'tipo_estado']);
    }

    // Slug legible desde el título; sufijo numérico si ya existe (incluyendo papelera, como el unique de BD).
    private function generarSlug(string $titulo, ?int $excludeId = null): string
    {
        $base = Str::limit(Str::slug($titulo), 200, '') ?: 'temporada';
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
        return PodcastTemporada::withTrashed()
            ->where('slug', $slug)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }

    private function aDto(PodcastTemporada $t): array
    {
        return [
            'id' => $t->id,
            'numero' => $t->numero,
            'titulo' => $t->titulo,
            'slug' => $t->slug,
            'descripcion' => $t->descripcion,
            'imagen_portada' => $t->imagen_portada,
            'imagen_portada_url' => $t->imagen_portada ? Storage::url($t->imagen_portada) : null,
            'estado_id' => $t->estado_id,
            'estado' => $t->estado?->tipo_estado,
            'episodios_count' => $t->episodios_count ?? 0,
            'episodios_activos_count' => $t->episodios_activos_count ?? 0,
            'created_at' => $t->created_at?->toDateTimeString(),
            'deleted_at' => $t->deleted_at?->toDateTimeString(),
        ];
    }
}
