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
        try {
            $validated = $request->validate([
                'area_formacion_id' => 'required|exists:areas_formacion,id',
                'tipo_evento' => 'required|string',
                'organizador_id' => 'required|exists:usuarios,id',
                'titulo' => 'required|string|max:255',
                'subtitulo' => 'nullable|string|max:255',
                'modo_evento' => 'required|string|max:150',
                
                'imagen_relacionada' => 'nullable|file|image',
                'url_folleto' => 'nullable|file|mimes:pdf', 
                
                'fecha_hora_inicio' => 'required|date',
                'fecha_hora_fin' => 'required|date|after_or_equal:fecha_hora_inicio',
                'modalidad' => 'required|string|in:Presencial,Virtual,Híbrido',
                'ubicacion' => 'nullable|string|max:255',
                
                'precio_jornada' => 'nullable|numeric|min:0',
                'precio_seminario' => 'nullable|numeric|min:0',
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
                'url_formulario_inscripcion' => 'nullable|url|max:255',
                
                'estilo_temario' => 'nullable|string',
                'estilo_expertos' => 'nullable|string',
                'estilo_card' => 'nullable|string',
                'estilo_plantilla' => 'nullable|string',
            ], [
                'area_formacion_id.required' => 'Es obligatorio seleccionar un Área de Formación.',
                'area_formacion_id.exists' => 'El Área de Formación seleccionada no es válida.',
                'organizador_id.required' => 'Debes asignar un responsable o comercial encargado al evento.',
                'organizador_id.exists' => 'El comercial seleccionado no se encuentra registrado.',

                'tipo_evento.required' => 'Debes clasificar el tipo de evento (Jornada, Módulo, etc.).',
                'titulo.required' => 'El título principal del evento es obligatorio.',
                'titulo.max' => 'El título no puede superar los 255 caracteres.',
                'subtitulo.max' => 'El subtítulo no puede superar los 255 caracteres.',
                'modo_evento.max' => 'La línea de formación (modo) es demasiado larga (máx. 150 caracteres).',
                'modo_evento.required' => 'Debes clasificar el tipo de evento (Jornada, Módulo, etc.).',

                'fecha_hora_inicio.required' => 'Define la fecha y hora de inicio de la jornada.',
                'fecha_hora_inicio.date' => 'El formato de la fecha de inicio es incorrecto.',
                'fecha_hora_fin.required' => 'Define la fecha y hora de cierre.',
                'fecha_hora_fin.date' => 'El formato de la fecha de fin es incorrecto.',
                'fecha_hora_fin.after_or_equal' => 'La fecha de finalización no puede ser anterior a la de inicio.',
                'modalidad.required' => 'Debes seleccionar la modalidad de asistencia.',
                'modalidad.in' => 'La modalidad debe ser Presencial, Virtual o Híbrido.',
                'ubicacion.max' => 'La dirección o ubicación es demasiado larga.',

                'precio_jornada.numeric' => 'El precio de la jornada debe ser un valor numérico.',
                'precio_jornada.min' => 'El precio de la jornada no puede ser negativo.',
                'precio_seminario.numeric' => 'El precio de la jornada debe ser un valor numérico.',
                'precio_seminario.min' => 'El precio de la jornada no puede ser negativo.',
                'precio_modulo.numeric' => 'El precio del módulo debe ser un valor numérico.',
                'precio_modulo.min' => 'El precio del módulo no puede ser negativo.',
                'precio_cng.numeric' => 'El precio del CNG debe ser un valor numérico.',
                'precio_cng.min' => 'El precio del CNG no puede ser negativo.',
                'precio_curso_intensivo.numeric' => 'El precio del curso intensivo debe ser numérico.',
                'precio_curso_intensivo.min' => 'El precio del curso intensivo no puede ser negativo.',
                'precio_diplomado.numeric' => 'El precio del diplomado debe ser un valor numérico.',
                'precio_diplomado.min' => 'El precio del diplomado no puede ser negativo.',

                'tiene_oferta_valor.boolean' => 'El interruptor de oferta de valor es inválido.',
                'oferta_valor.required_if' => 'Como activaste la Oferta de Valor, debes escribir en qué consiste.',
                'oferta_valor.max' => 'La descripción de la oferta es demasiado extensa.',

                'conferencistas.required' => 'Es indispensable asignar al menos un experto al evento.',
                'conferencistas.array' => 'Formato de expertos inválido.',
                'conferencistas.min' => 'Debes seleccionar por lo menos un experto.',
                'conferencistas.*.exists' => 'Uno de los expertos seleccionados no existe o fue eliminado.',

                'contenido_tematico.required' => 'El temario académico no puede estar vacío.',
                'contenido_tematico.array' => 'El formato del temario es inválido.',
                'contenido_tematico.min' => 'Asegúrate de configurar al menos un módulo académico.',
                'contenido_tematico.*.tema.required' => 'Todos los módulos deben tener un nombre o tema.',
                'contenido_tematico.*.subtemas.array' => 'El formato de los puntos clave (subtemas) es inválido.',
                'contenido_tematico.*.subtemas.min' => 'Si activas los puntos clave, debes agregar al menos uno.',

                'imagen_relacionada.image' => 'El archivo subido para el banner no es válido (solo JPG, PNG, WEBP).',
                'url_folleto.mimes' => 'El documento de soporte técnico debe ser estrictamente formato PDF.',
                'url_formulario_inscripcion.url' => 'El enlace de inscripción debe ser una URL válida (ej: https://formulario.com).',
                'url_formulario_inscripcion.max' => 'El enlace de inscripción es demasiado largo.',
                'color_hex_secundario.max' => 'El código de color secundario no tiene un formato HEX válido.',
            ]);

            $modo = strtolower($validated['modo_evento'] ?? '');
            $modoSlug = 'evt';

            if (str_contains($modo, 'jornada')) {
                $modoSlug = 'jor';
            } elseif (str_contains($modo, 'congreso')) {
                preg_match('/^([ixv]+)\s+congreso/i', $validated['modo_evento'], $matches);
                if (!empty($matches[1])) {
                    $modoSlug = strtolower($matches[1]) . '-cng';
                } else {
                    $modoSlug = 'cng';
                }
            } elseif (str_contains($modo, 'módulo') || str_contains($modo, 'modulo')) {
                $modoSlug = 'mod';
            } elseif (str_contains($modo, 'curso')) {
                $modoSlug = 'cur';
            } elseif (str_contains($modo, 'diplomado')) {
                $modoSlug = 'dip';
            } elseif (str_contains($modo, 'seminario')) {
                $modoSlug = 'sem';
            } elseif (str_contains($modo, 'taller')) {
                $modoSlug = 'tll';
            } else {
                $modoSlug = Str::slug(substr($modo, 0, 10));
            }

            $tituloSlug = Str::slug($validated['titulo']);
            
            $slugParts = explode('-', $evento->slug);
            $shortUuid = (strlen($slugParts[0] ?? '') === 8) ? $slugParts[0] : explode('-', Str::uuid()->toString())[0];

            $validated['slug'] = "{$shortUuid}-{$modoSlug}-{$tituloSlug}";
            // ----------------------------------------

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

                $validated['oferta_valor'] = $validated['tiene_oferta_valor'] ? ($validated['oferta_valor'] ?? null) : null;

                $evento->update($validated);

                if (!empty($validated['conferencistas'])) {
                    $evento->conferencistas()->sync($validated['conferencistas']);
                }
            });

            Movimiento::registrar(
                tipo: 'actualizacion',
                modulo: 'eventos',
                descripcion: "El usuario actualizó el evento: " . $evento->titulo
            );

            return back()->with('success', '¡El evento "' . $evento->titulo . '" se ha actualizado correctamente!');

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error crítico al actualizar el Evento: ' . $e->getMessage());
            return back()->withErrors([
                'general' => 'Ocurrió un error inesperado al actualizar el evento.'
            ]);
        }
    }


    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'id_origen_duplicado' => 'nullable|exists:eventos,id',
                'area_formacion_id' => 'required|exists:areas_formacion,id',
                'tipo_evento' => 'required|string',
                'organizador_id' => 'required|exists:usuarios,id',
                'titulo' => 'required|string|max:255',
                'subtitulo' => 'nullable|string|max:255',
                'modo_evento' => 'required|string|max:150',
                'imagen_relacionada' => 'nullable|file|image',
                'fecha_hora_inicio' => 'required|date',
                'fecha_hora_fin' => 'required|date|after_or_equal:fecha_hora_inicio',
                'modalidad' => 'required|string|in:Presencial,Virtual,Híbrido',
                'ubicacion' => 'nullable|string|max:255',
                'precio_seminario' => 'nullable|numeric|min:0',
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
                'url_folleto' => 'required|file|mimes:pdf',
                'url_formulario_inscripcion' => 'required|url|max:255',
                'estilo_temario' => 'nullable|string',
                'estilos_expertos' => 'nullable|string',
                'estilo_card' => 'nullable|string',
                'estilo_plantilla' => 'nullable|string',
            ], [

                'id_origen_duplicado.exists' => 'El evento que intentas duplicar ya no existe en el sistema.',
                'area_formacion_id.required' => 'Es obligatorio seleccionar un Área de Formación.',
                'area_formacion_id.exists' => 'El Área de Formación seleccionada no es válida.',
                'organizador_id.required' => 'Debes asignar un responsable o comercial encargado al evento.',
                'organizador_id.exists' => 'El comercial seleccionado no se encuentra registrado.',

                'tipo_evento.required' => 'Debes clasificar el tipo de evento (Jornada, Módulo, etc.).',
                'titulo.required' => 'El título principal del evento es obligatorio.',
                'titulo.max' => 'El título no puede superar los 255 caracteres.',
                'subtitulo.max' => 'El subtítulo no puede superar los 255 caracteres.',
                'modo_evento.max' => 'La línea de formación (modo) es demasiado larga (máx. 150 caracteres).',
                'modo_evento.required' => 'Debes clasificar el tipo de evento (Jornada, Módulo, etc.).',

                'fecha_hora_inicio.required' => 'Define la fecha y hora de inicio de la jornada.',
                'fecha_hora_inicio.date' => 'El formato de la fecha de inicio es incorrecto.',
                'fecha_hora_fin.required' => 'Define la fecha y hora de cierre.',
                'fecha_hora_fin.date' => 'El formato de la fecha de fin es incorrecto.',
                'fecha_hora_fin.after_or_equal' => 'La fecha de finalización no puede ser anterior a la de inicio.',
                'modalidad.required' => 'Debes seleccionar la modalidad de asistencia.',
                'modalidad.in' => 'La modalidad debe ser Presencial, Virtual o Híbrido.',
                'ubicacion.max' => 'La dirección o ubicación es demasiado larga.',

                'precio_jornada.numeric' => 'El precio de la jornada debe ser un valor numérico.',
                'precio_jornada.min' => 'El precio de la jornada no puede ser negativo.',
                'precio_modulo.numeric' => 'El precio del módulo debe ser un valor numérico.',
                'precio_modulo.min' => 'El precio del módulo no puede ser negativo.',
                'precio_cng.numeric' => 'El precio del CNG debe ser un valor numérico.',
                'precio_cng.min' => 'El precio del CNG no puede ser negativo.',
                'precio_curso_intensivo.numeric' => 'El precio del curso intensivo debe ser numérico.',
                'precio_curso_intensivo.min' => 'El precio del curso intensivo no puede ser negativo.',
                'precio_diplomado.numeric' => 'El precio del diplomado debe ser un valor numérico.',
                'precio_diplomado.min' => 'El precio del diplomado no puede ser negativo.',

                'tiene_oferta_valor.boolean' => 'El interruptor de oferta de valor es inválido.',
                'oferta_valor.required_if' => 'Como activaste la Oferta de Valor, debes escribir en qué consiste.',
                'oferta_valor.max' => 'La descripción de la oferta es demasiado extensa.',

                'conferencistas.required' => 'Es indispensable asignar al menos un experto al evento.',
                'conferencistas.array' => 'Formato de expertos inválido.',
                'conferencistas.min' => 'Debes seleccionar por lo menos un experto.',
                'conferencistas.*.exists' => 'Uno de los expertos seleccionados no existe o fue eliminado.',

                'contenido_tematico.required' => 'El temario académico no puede estar vacío.',
                'contenido_tematico.array' => 'El formato del temario es inválido.',
                'contenido_tematico.min' => 'Asegúrate de configurar al menos un módulo académico.',
                'contenido_tematico.*.tema.required' => 'Todos los módulos deben tener un nombre o tema.',
                'contenido_tematico.*.subtemas.array' => 'El formato de los puntos clave (subtemas) es inválido.',
                'contenido_tematico.*.subtemas.min' => 'Si activas los puntos clave, debes agregar al menos uno.',

                'imagen_relacionada.image' => 'El archivo subido para el banner no es válido (solo JPG, PNG, WEBP).',
                'url_folleto.mimes' => 'El documento de soporte técnico debe ser estrictamente formato PDF.',
                'url_folleto.required' => 'El folleto es obligatorio.',
                'url_formulario_inscripcion.url' => 'El enlace de inscripción debe ser una URL válida (ej: https://formulario.com).',
                'url_formulario_inscripcion.required' => 'El enlace de inscripción es obligarorio.',
                'url_formulario_inscripcion.max' => 'El enlace de inscripción es demasiado largo.',
                'color_hex_secundario.max' => 'El código de color secundario no tiene un formato HEX válido.',
            ]);


            $modo = strtolower($validated['modo_evento'] ?? '');
            $modoSlug = 'evt';

            if (str_contains($modo, 'jornada')) {
                $modoSlug = 'jor';
            } elseif (str_contains($modo, 'congreso')) {

                preg_match('/^([ixv]+)\s+congreso/i', $validated['modo_evento'], $matches);
                if (!empty($matches[1])) {
                    $modoSlug = strtolower($matches[1]) . '-cng';
                } else {
                    $modoSlug = 'cng';
                }
            } elseif (str_contains($modo, 'módulo') || str_contains($modo, 'módulo')) {
                $modoSlug = 'mod';
            } elseif (str_contains($modo, 'curso')) {
                $modoSlug = 'cur';
            } elseif (str_contains($modo, 'diplomado')) {
                $modoSlug = 'dip';
            } elseif (str_contains($modo, 'seminario')) {
                $modoSlug = 'sem';
            } elseif (str_contains($modo, 'taller')) {
                $modoSlug = 'tll';
            } else {
                $modoSlug = Str::slug(substr($modo, 0, 10));
            }

            $tituloSlug = Str::slug($validated['titulo']);
            $shortUuid = explode('-', Str::uuid()->toString())[0];


            $slugGenerado = "{$shortUuid}-{$modoSlug}-{$tituloSlug}";


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

            $evento = DB::transaction(function () use ($validated, $rutaImagen, $rutaFolleto, $slugGenerado) {
                $contenido = ContenidoTematico::create([
                    'modulos' => $validated['contenido_tematico'],
                    'alcance' => $validated['subtitulo'] ?? null,
                ]);

                $nuevoEvento = Evento::create([
                    'organizador_id' => $validated['organizador_id'],
                    'contenido_tematico_id' => $contenido->id,
                    'estado_id' => 1,
                    'area_formacion_id' => $validated['area_formacion_id'],
                    'tipo_evento' => $validated['tipo_evento'],
                    'modo_evento' => $validated['modo_evento'],
                    'titulo' => $validated['titulo'],

                    'slug' => $slugGenerado,

                    'subtitulo' => $validated['subtitulo'],
                    'imagen_relacionada' => $rutaImagen,
                    'modalidad' => $validated['modalidad'],
                    'url_folleto' => $rutaFolleto,
                    'url_formulario_inscripcion' => $validated['url_formulario_inscripcion'] ?? null,
                    'ubicacion' => $validated['ubicacion'],
                    'fecha_hora_inicio' => $validated['fecha_hora_inicio'],
                    'fecha_hora_fin' => $validated['fecha_hora_fin'],

                    'precio_jornada' => $validated['precio_jornada'] ?? 0,
                    'precio_modulo' => $validated['precio_modulo'] ?? 0,
                    'precio_cng' => $validated['precio_cng'] ?? 0,
                    'precio_curso_intensivo' => $validated['precio_curso_intensivo'] ?? 0,
                    'precio_diplomado' => $validated['precio_diplomado'] ?? 0,

                    'tiene_oferta_valor' => $validated['tiene_oferta_valor'],
                    'oferta_valor' => $validated['tiene_oferta_valor'] ? $validated['oferta_valor'] : null,
                    'color_hex_secundario' => $validated['color_hex_secundario'],
                    'texto_dinamico' => $validated['texto_dinamico'],

                    'estilo_temario' => $validated['estilo_temario'] ?? 'lista',
                    'estilo_expertos' => $validated['estilos_expertos'] ?? 'lista',
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