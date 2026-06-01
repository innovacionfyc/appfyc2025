<?php

namespace App\Http\Controllers\AccesosVirtuales;

use App\Http\Controllers\Controller;
use App\Models\AccesoVirtual;
use App\Models\Estado;
use App\Models\Movimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccesoVirtualController extends Controller
{
    public function index(): Response
    {
        $accesos = AccesoVirtual::with(['estado', 'organizador.perfilOrganizador'])
            ->latest()
            ->get()
            ->map(fn($a) => [
                'id'                => $a->id,
                'nombre'            => $a->nombre,
                'slug'              => $a->slug,
                'descripcion'       => $a->descripcion,
                'fecha'             => $a->fecha ? $a->fecha->format('Y-m-d') : null,
                'hora'              => $a->hora,
                'url_zoom'          => $a->url_zoom,
                'estado_id'         => $a->estado_id,
                'estado'            => $a->estado,
                'imagen_banner'     => $a->imagen_banner,
                'imagen_banner_url' => $a->imagen_banner
                    ? Storage::url($a->imagen_banner)
                    : null,
            ]);

        return Inertia::render('AccesosVirtuales/AccesosVirtuales', [
            'accesos' => $accesos,
            'estados' => Estado::all(),
        ]);
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'nombre'        => 'required|string|max:200',
                'descripcion'   => 'nullable|string|max:500',
                'fecha'         => 'nullable|date',
                'hora'          => 'nullable|date_format:H:i',
                'url_zoom'      => 'required|url|max:500',
                'imagen_banner' => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:4096',
                'estado_id'     => 'required|exists:estados,id',
            ], [
                'nombre.required'         => 'El nombre del acceso virtual es obligatorio.',
                'nombre.max'              => 'El nombre no puede superar los 200 caracteres.',
                'url_zoom.required'       => 'El link de la reunión virtual es obligatorio.',
                'url_zoom.url'            => 'El link debe ser una URL válida (ej: https://zoom.us/j/...).',
                'url_zoom.max'            => 'El link de la reunión es demasiado largo.',
                'fecha.date'              => 'El formato de la fecha es incorrecto.',
                'hora.date_format'        => 'La hora debe estar en formato HH:MM (ej: 09:00).',
                'estado_id.required'      => 'El estado del acceso virtual es obligatorio.',
                'estado_id.exists'        => 'El estado seleccionado no es válido.',
                'imagen_banner.image'     => 'El archivo debe ser una imagen.',
                'imagen_banner.mimes'     => 'El banner debe ser JPG, PNG o WEBP.',
                'imagen_banner.max'       => 'El banner no puede superar los 4 MB.',
            ]);

            $slug = $this->generarSlug($validated['nombre']);

            $accesoVirtual = AccesoVirtual::create([
                'organizador_id' => Auth::id(),
                'estado_id'      => $validated['estado_id'],
                'nombre'         => $validated['nombre'],
                'slug'           => $slug,
                'descripcion'    => $validated['descripcion'] ?? null,
                'fecha'          => $validated['fecha'] ?? null,
                'hora'           => $validated['hora'] ?? null,
                'url_zoom'       => $validated['url_zoom'],
                'imagen_banner'  => $request->hasFile('imagen_banner')
                    ? $request->file('imagen_banner')->store('accesos-virtuales/banners', 'public')
                    : null,
            ]);

            Movimiento::registrar(
                tipo: 'registro',
                modulo: 'accesos-virtuales',
                descripcion: "Se creó el acceso virtual: " . $accesoVirtual->nombre
            );

            return back()->with('success', '¡El acceso virtual "' . $accesoVirtual->nombre . '" se creó correctamente!');

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error al crear acceso virtual: ' . $e->getMessage());
            return back()->withErrors(['general' => 'Ocurrió un error inesperado al crear el acceso virtual.']);
        }
    }

    public function update(Request $request, AccesoVirtual $accesoVirtual)
    {
        try {
            $validated = $request->validate([
                'nombre'        => 'required|string|max:200',
                'descripcion'   => 'nullable|string|max:500',
                'fecha'         => 'nullable|date',
                'hora'          => 'nullable|date_format:H:i',
                'url_zoom'      => 'required|url|max:500',
                'imagen_banner' => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:4096',
                'estado_id'     => 'required|exists:estados,id',
            ], [
                'nombre.required'         => 'El nombre del acceso virtual es obligatorio.',
                'nombre.max'              => 'El nombre no puede superar los 200 caracteres.',
                'url_zoom.required'       => 'El link de la reunión virtual es obligatorio.',
                'url_zoom.url'            => 'El link debe ser una URL válida.',
                'url_zoom.max'            => 'El link de la reunión es demasiado largo.',
                'fecha.date'              => 'El formato de la fecha es incorrecto.',
                'hora.date_format'        => 'La hora debe estar en formato HH:MM (ej: 09:00).',
                'estado_id.required'      => 'El estado del acceso virtual es obligatorio.',
                'estado_id.exists'        => 'El estado seleccionado no es válido.',
                'imagen_banner.image'     => 'El archivo debe ser una imagen.',
                'imagen_banner.mimes'     => 'El banner debe ser JPG, PNG o WEBP.',
                'imagen_banner.max'       => 'El banner no puede superar los 4 MB.',
            ]);

            // Regenerar slug solo si el nombre cambió, conservando el uuid original
            if (Str::slug($validated['nombre']) !== Str::slug($accesoVirtual->nombre)) {
                $validated['slug'] = $this->generarSlug(
                    $validated['nombre'],
                    $accesoVirtual->id,
                    explode('-', $accesoVirtual->slug)[0]
                );
            }

            // Manejo de banner: si llega nuevo archivo se guarda; si no, se preserva el existente
            if ($request->hasFile('imagen_banner')) {
                $validated['imagen_banner'] = $request->file('imagen_banner')
                    ->store('accesos-virtuales/banners', 'public');
            } else {
                unset($validated['imagen_banner']);
            }

            $accesoVirtual->update($validated);

            Movimiento::registrar(
                tipo: 'actualizacion',
                modulo: 'accesos-virtuales',
                descripcion: "Se actualizó el acceso virtual: " . $accesoVirtual->nombre
            );

            return back()->with('success', '¡El acceso virtual "' . $accesoVirtual->nombre . '" se actualizó correctamente!');

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error al actualizar acceso virtual: ' . $e->getMessage());
            return back()->withErrors(['general' => 'Ocurrió un error inesperado al actualizar el acceso virtual.']);
        }
    }

    public function destroy(AccesoVirtual $accesoVirtual)
    {
        try {
            $nombre = $accesoVirtual->nombre;
            $accesoVirtual->delete();

            Movimiento::registrar(
                tipo: 'eliminacion',
                modulo: 'accesos-virtuales',
                descripcion: "Se eliminó el acceso virtual: " . $nombre
            );

            return back()->with('success', 'El acceso virtual fue eliminado correctamente.');

        } catch (\Exception $e) {
            return back()->with('error', 'Error al eliminar el acceso virtual: ' . $e->getMessage());
        }
    }

    public function restore($id)
    {
        $accesoVirtual = AccesoVirtual::withTrashed()->findOrFail($id);
        $accesoVirtual->restore();

        Movimiento::registrar(
            tipo: 'restauracion',
            modulo: 'accesos-virtuales',
            descripcion: "Se restauró el acceso virtual: " . $accesoVirtual->nombre
        );

        return back()->with('success', 'El acceso virtual fue restaurado correctamente.');
    }

    // Genera slug único: {shortUuid}-{nombreSlug}
    // Si se pasa $excludeId, excluye ese registro al verificar unicidad (para updates)
    // Si se pasa $existingUuid, lo reutiliza (para mantener consistencia en edición)
    private function generarSlug(string $nombre, ?int $excludeId = null, ?string $existingUuid = null): string
    {
        $nombreSlug = Str::slug($nombre);
        $shortUuid  = $existingUuid ?? explode('-', Str::uuid()->toString())[0];
        $slug       = "{$shortUuid}-{$nombreSlug}";

        $query = AccesoVirtual::where('slug', $slug);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        while ($query->exists()) {
            $shortUuid = explode('-', Str::uuid()->toString())[0];
            $slug      = "{$shortUuid}-{$nombreSlug}";
            $query     = AccesoVirtual::where('slug', $slug);
            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }
        }

        return $slug;
    }
}
