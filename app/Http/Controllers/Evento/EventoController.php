<?php

namespace App\Http\Controllers\Evento;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\ContenidoTematico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EventoController extends Controller
{
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                // Paso 1: Identidad
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
                'contenido_tematico.*.subtemas' => 'required|array|min:1',
                'contenido_tematico.*.subtemas.*' => 'required|string',

                'color_hex_secundario' => 'nullable|string|max:7',
                'texto_dinamico' => 'nullable|string',
                'imagen_relacionada' => 'nullable|file|image',
                'url_folleto' => 'nullable|file|mimes:pdf',
                'url_formulario_inscripcion' => 'nullable|url|max:255',
            ]);

            $rutaImagen = null;
            $rutaFolleto = null;

            // Manejo de Archivos
            if ($request->hasFile('imagen_relacionada')) {
                $rutaImagen = $request->file('imagen_relacionada')->store('eventos/imagenes', 'public');
            }
            if ($request->hasFile('url_folleto')) {
                $rutaFolleto = $request->file('url_folleto')->store('eventos/folletos', 'public');
            }

            // Transacción para asegurar integridad
            $evento = DB::transaction(function () use ($validated, $rutaImagen, $rutaFolleto) {

                // 1. Crear el Contenido Temático (JSON)
                $contenido = ContenidoTematico::create([
                    'modulos' => $validated['contenido_tematico'],
                    'alcance' => $validated['subtitulo'] ?? null,
                ]);

                // 2. Crear el Evento
                $nuevoEvento = Evento::create([
                    'organizador_id' => $validated['organizador_id'],
                    'contenido_tematico_id' => $contenido->id,
                   
                    'area_formacion_id' => $validated['area_formacion_id'],
                    'estado_id' => 1,

                    'titulo' => $validated['titulo'],
                    'modo_evento' => $validated['modo_evento'],
                    'subtitulo' => $validated['subtitulo'],
                    'modalidad' => $validated['modalidad'],

                    'fecha_hora_inicio' => $validated['fecha_hora_inicio'],
                    'fecha_hora_fin' => $validated['fecha_hora_fin'],

                    'ubicacion' => $validated['ubicacion'],
                    'precio_jornada' => $validated['precio_jornada'],
                    'precio_modulo' => $validated['precio_modulo'],

                    'texto_dinamico' => $validated['texto_dinamico'],
                    'color_hex_secundario' => $validated['color_hex_secundario'],
                    'url_formulario_inscripcion' => $validated['url_formulario_inscripcion'],

                    'imagen_relacionada' => $rutaImagen,
                    'url_folleto' => $rutaFolleto,
                ]);

                if (!empty($validated['conferencistas'])) {
                    $nuevoEvento->conferencistas()->sync($validated['conferencistas']);
                }

                return $nuevoEvento;
            });

            return redirect()->route('admin.dashboard')->with('success', '¡El evento "' . $evento->titulo . '" se ha publicado correctamente!');

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error crítico al crear Evento: ' . $e->getMessage());

            return back()->withErrors([
                'general' => 'Ocurrió un error inesperado al procesar el evento. Por favor revise que los textos no sean demasiado largos.'
            ]);
        }
    }
}