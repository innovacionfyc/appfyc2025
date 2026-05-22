<?php

namespace App\Http\Controllers\Evento;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\ContenidoTematico;
use App\Models\Movimiento;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Models\AreaFormacion;
use App\Models\Estado;
use App\Models\Usuario;
use App\Models\PerfilConferencista;
use App\Models\FormularioBase;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Str;


class EventoController extends Controller
{
    use SoftDeletes;

    public function show(): Response
    {
        $eventos = Evento::with([
            'areaFormacion',
            'estado',
            'conferencistas',
            'contenidoTematico',
            'organizador.perfilOrganizador'
        ])
            ->where('estado_id', 1)
            ->latest()
            ->get();

        $organizador = Usuario::whereHas('perfilOrganizador', function ($query) {
            $query->where('rol_id', 3);
        })
            ->with('perfilOrganizador')
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'nombre' => $u->perfilOrganizador
                    ? $u->perfilOrganizador->primer_nombre . ' ' . $u->perfilOrganizador->segundo_nombre . ' ' . $u->perfilOrganizador->primer_apellido
                    : $u->email
            ]);

        $estados = Estado::all();
        $areas = AreaFormacion::all();
        $conferencistas = PerfilConferencista::with('areaEncargada')->get();

        return Inertia::render('Eventos/Eventos', [
            'eventos' => $eventos,
            'areas' => $areas,
            'estados' => $estados,
            'conferencistas' => $conferencistas,
            'formularios' => FormularioBase::all(),
            'organizador' => $organizador
        ]);
    }

    public function calendario(): Response
    {
        $eventos = Evento::with([
            'areaFormacion',
            'estado',
            'conferencistas',
            'contenidoTematico',
            'organizador.perfilOrganizador'
        ])
            ->where('estado_id', 1)
            ->latest()
            ->get();

        $organizador = Usuario::whereHas('perfilOrganizador', function ($query) {
            $query->where('rol_id', 3);
        })
            ->with('perfilOrganizador')
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'nombre' => $u->perfilOrganizador
                    ? $u->perfilOrganizador->primer_nombre . ' ' . $u->perfilOrganizador->segundo_nombre . ' ' . $u->perfilOrganizador->primer_apellido
                    : $u->email
            ]);

        $estados = Estado::all();
        $areas = AreaFormacion::all();
        $conferencistas = PerfilConferencista::with('areaEncargada')->get();

        return Inertia::render('Eventos/Calendario', [
            'eventos' => $eventos,
            'areas' => $areas,
            'estados' => $estados,
            'conferencistas' => $conferencistas,
            'formularios' => FormularioBase::all(),
            'organizador' => $organizador
        ]);
    }

    public function destroy(Evento $evento)
    {
        try {
            DB::transaction(function () use ($evento) {
                if ($evento->imagen_relacionada) {
                    $cleanImage = str_replace(['/storage/', 'storage/'], '', $evento->imagen_relacionada);
                    Storage::disk('public')->delete($cleanImage);
                }

                if ($evento->url_folleto) {
                    $cleanFolleto = str_replace(['/storage/', 'storage/'], '', $evento->url_folleto);
                    Storage::disk('public')->delete($cleanFolleto);
                }

                $evento->conferencistas()->detach();

                if ($evento->contenidoTematico) {
                    $evento->contenidoTematico()->delete();
                }

                $evento->delete();
            });

            return back();

        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error al intentar eliminar el evento: ' . $e->getMessage());
        }
    }

    public function restore($id)
    {

        $evento = Evento::withTrashed()->findOrFail($id);
        $evento->restore();

        Movimiento::registrar('restauracion', 'eventos', "Se restauró la jornada: " . $evento->titulo);

        return back()->with('success', 'La jornada ha sido restaurada con éxito.');
    }



    public function update(Request $request, Evento $evento)
    {
        $validated = $request->validate([
            'modo_evento' => 'nullable|string|max:150',
            'titulo' => 'required|string|max:255',
            'subtitulo' => 'nullable|string|max:255',
            'area_formacion_id' => 'required|exists:areas_formacion,id',
            'organizador_id' => 'required|exists:usuarios,id',

            'fecha_hora_inicio' => 'required|date',
            'fecha_hora_fin' => 'required|date|after_or_equal:fecha_hora_inicio',

            'modalidad' => 'required|string|in:Presencial,Virtual,Híbrido',
            'ubicacion' => 'nullable|string|max:255',
            'precio_jornada' => 'nullable|numeric|min:0',
            'precio_modulo' => 'nullable|numeric|min:0',

            'conferencistas' => 'required|array|min:1',
            'conferencistas.*' => 'exists:perfil_conferencistas,id',
            'contenido_tematico' => 'required|array|min:1',
            'contenido_tematico.*.tema' => 'required|string',
            'contenido_tematico.*.subtemas' => 'nullable|array',
            'contenido_tematico.*.subtemas.*' => 'nullable|string',

            'color_hex_secundario' => 'nullable|string|max:7',
            'texto_dinamico' => 'nullable|string',
            'imagen_relacionada' => 'nullable|file|image',
            'url_folleto' => 'nullable|file|mimes:pdf',
            'url_formulario_inscripcion' => 'nullable|url|max:255',
        ]);

        DB::transaction(function () use (&$validated, $request, $evento) {

            $evento->contenidoTematico->update([
                'modulos' => $validated['contenido_tematico'],
                'alcance' => $validated['subtitulo'] ?? null
            ]);

            if ($request->hasFile('imagen_relacionada')) {
                $validated['imagen_relacionada'] = $request->file('imagen_relacionada')->store('eventos/imagenes', 'public');
            } else {
                unset($validated['imagen_relacionada']);
            }

            if ($request->hasFile('url_folleto')) {
                $validated['url_folleto'] = $request->file('url_folleto')->store('eventos/folletos', 'public');
            } else {
                unset($validated['url_folleto']);
            }

            $evento->update($validated);

            $evento->conferencistas()->sync($validated['conferencistas']);
        });

        Movimiento::registrar(
            tipo: 'actualizacion',
            modulo: 'eventos',
            descripcion: "El usuario actualizó la jornada: " . $evento->titulo
        );

        return back()->with('success', 'Jornada académica actualizada correctamente.');
    }


    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'id_origen_duplicado' => 'nullable|exists:eventos,id',
                'tipo_evento' => 'required|string',
                'modo_evento' => 'nullable|string|max:150',
                'titulo' => 'required|string|max:255',
                'subtitulo' => 'nullable|string|max:255',
                'area_formacion_id' => 'required|exists:areas_formacion,id',
                'organizador_id' => 'required|exists:usuarios,id',
                'fecha_hora_inicio' => 'required|date',
                'fecha_hora_fin' => 'required|date|after_or_equal:fecha_hora_inicio',
                'modalidad' => 'required|string|in:Presencial,Virtual,Híbrido',
                'ubicacion' => 'nullable|string|max:255',
                'precio_jornada' => 'nullable|numeric|min:0',
                'precio_modulo' => 'nullable|numeric|min:0',
                'precio_cng' => 'nullable|numeric|min:0',
                'precio_curso_intensivo' => 'nullable|numeric|min:0',
                'precio_diplomado' => 'nullable|numeric|min:0',
                'tiene_oferta_valor' => 'boolean',
                'oferta_valor' => 'nullable|required_if:tiene_oferta_valor,true|string|max:255',
                'conferencistas' => 'required|array|min:1',
                'conferencistas.*' => 'exists:perfil_conferencistas,id',
                'contenido_tematico' => 'required|array|min:1',
                'contenido_tematico.*.tema' => 'required|string',
                'contenido_tematico.*.subtemas' => 'nullable|array|min:1',
                'contenido_tematico.*.subtemas.*' => 'nullable|string',
                'color_hex_secundario' => 'nullable|string|max:7',
                'texto_dinamico' => 'nullable|string',
                'imagen_relacionada' => 'nullable|file|image',
                'url_folleto' => 'nullable|file|mimes:pdf',
                'url_formulario_inscripcion' => 'nullable|url|max:255',
            ]);

            $rutaImagen = null;
            $rutaFolleto = null;

            $copyServerFile = function ($path, $folder) {
                if (!$path)
                    return null;
                $cleanPath = str_replace(['/storage/', 'storage/'], '', $path);

                if (Storage::disk('public')->exists($cleanPath)) {
                    $extension = pathinfo($cleanPath, PATHINFO_EXTENSION);
                    $newName = $folder . '/' . Str::random(20) . '.' . $extension;
                    Storage::disk('public')->copy($cleanPath, $newName);
                    return $newName;
                }
                return null;
            };

            if ($request->hasFile('imagen_relacionada')) {
                $rutaImagen = $request->file('imagen_relacionada')->store('eventos/imagenes', 'public');
            } elseif ($request->filled('id_origen_duplicado')) {
                $eventoOrigen = Evento::find($request->id_origen_duplicado);
                $rutaImagen = $copyServerFile($eventoOrigen->imagen_relacionada, 'eventos/imagenes');
            }

            if ($request->hasFile('url_folleto')) {
                $rutaFolleto = $request->file('url_folleto')->store('eventos/folletos', 'public');
            } elseif ($request->filled('id_origen_duplicado')) {
                $eventoOrigen = $eventoOrigen ?? Evento::find($request->id_origen_duplicado);
                $rutaFolleto = $copyServerFile($eventoOrigen->url_folleto, 'eventos/folletos');
            }

            $evento = DB::transaction(function () use ($validated, $rutaImagen, $rutaFolleto) {
                $contenido = ContenidoTematico::create([
                    'modulos' => $validated['contenido_tematico'],
                    'alcance' => $validated['subtitulo'] ?? null,
                ]);

               $nuevoEvento = Evento::create([
                'organizador_id' => $validated['organizador_id'],
                'contenido_tematico_id' => $contenido->id,
                'area_formacion_id' => $validated['area_formacion_id'],
                'estado_id' => 1,
                'tipo_evento' => $validated['tipo_evento'], 
                'titulo' => $validated['titulo'],
                'subtitulo' => $validated['subtitulo'],
                'modalidad' => $validated['modalidad'],
                'fecha_hora_inicio' => $validated['fecha_hora_inicio'],
                'fecha_hora_fin' => $validated['fecha_hora_fin'],
                'ubicacion' => $validated['ubicacion'],
                
                'precio_jornada' => $validated['precio_jornada'] ?? 0,
                'precio_modulo' => $validated['precio_modulo'] ?? 0,
                'precio_cng' => $validated['precio_cng'] ?? 0,
                'precio_curso_intensivo' => $validated['precio_curso_intensivo'] ?? 0,
                'precio_diplomado' => $validated['precio_diplomado'] ?? 0,
                
                'oferta_valor' => $validated['tiene_oferta_valor'] ? $validated['oferta_valor'] : null,
                
                'texto_dinamico' => $validated['texto_dinamico'],
                'color_hex_secundario' => $validated['color_hex_secundario'],
                'url_formulario_inscripcion' => $validated['url_formulario_inscripcion'],
                'imagen_relacionada' => $rutaImagen,
                'url_folleto' => $rutaFolleto,
                'modo_evento' => "pendiente por saber que es"
            ]);

                if (!empty($validated['conferencistas'])) {
                    $nuevoEvento->conferencistas()->sync($validated['conferencistas']);
                }

                return $nuevoEvento;
            });

            Movimiento::registrar(
                tipo: $request->filled('id_origen_duplicado') ? 'duplicacion_manual' : 'registro',
                modulo: 'eventos',
                descripcion: $request->filled('id_origen_duplicado')
                ? "El usuario duplicó el evento: " . $evento->titulo
                : "El usuario creó el evento: " . $evento->titulo
            );

            return back()->with('success', '¡El evento "' . $evento->titulo . '" se ha procesado correctamente!');

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error crítico al procesar el Evento: ' . $e->getMessage());
            return back()->withErrors([
                'general' => 'Ocurrió un error inesperado al procesar el evento.'
            ]);
        }
    }

    public function papelera(): Response
    {
        $eventos = Evento::with([
            'areaFormacion',
            'estado',
            'conferencistas',
            'contenidoTematico',
            'organizador.perfilOrganizador'
        ])
            ->latest()
            ->get();

        $organizador = Usuario::whereHas('perfilOrganizador', function ($query) {
            $query->where('rol_id', 3);
        })
            ->with('perfilOrganizador')
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'nombre' => $u->perfilOrganizador
                    ? $u->perfilOrganizador->primer_nombre . ' ' . $u->perfilOrganizador->segundo_nombre . ' ' . $u->perfilOrganizador->primer_apellido
                    : $u->email
            ]);

        $estados = Estado::all();
        $areas = AreaFormacion::all();
        $conferencistas = PerfilConferencista::with('areaEncargada')->get();

        return Inertia::render('Eventos/Papelera', [
            'eventos' => $eventos,
            'areas' => $areas,
            'estados' => $estados,
            'conferencistas' => $conferencistas,
            'formularios' => FormularioBase::all(),
            'organizador' => $organizador
        ]);
    }

    public function archivados(): Response
    {
        $eventos = Evento::with([
            'areaFormacion',
            'estado',
            'conferencistas',
            'contenidoTematico',
            'organizador.perfilOrganizador'
        ])
            ->where('estado_id', 5)
            ->latest()
            ->get();


        $organizador = Usuario::whereHas('perfilOrganizador', function ($query) {
            $query->where('rol_id', 3);
        })
            ->with('perfilOrganizador')
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'nombre' => $u->perfilOrganizador
                    ? $u->perfilOrganizador->primer_nombre . ' ' . $u->perfilOrganizador->segundo_nombre . ' ' . $u->perfilOrganizador->primer_apellido
                    : $u->email
            ]);

        return Inertia::render('Eventos/Papelera', [
            'eventos' => $eventos,
            'areas' => AreaFormacion::all(),
            'estados' => Estado::all(),
            'conferencistas' => PerfilConferencista::with('areaEncargada')->get(),
            'formularios' => FormularioBase::all(),
            'organizador' => $organizador
        ]);
    }
}