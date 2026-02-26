<?php

namespace App\Http\Controllers\Evento;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\ContenidoTematico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // Agregado para logs de errores
use Illuminate\Validation\ValidationException;

class EventoController extends Controller
{
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'titulo' => 'required|string|max:255',
                'subtitulo' => 'nullable|string|max:255',
                
                // Alcance se valida, pero pertenece a la tabla contenidos_tematicos
                'alcance' => 'nullable|string|max:255', 
                
                'area_formacion_id' => 'required|exists:areas_formacion,id',
                'estado_id' => 'required|exists:estados,id',

                'modalidad' => 'required|string|in:Presencial,Virtual,Híbrido',
                'fecha_hora' => 'required|date',
                
                // Cambiado a 'string' sin max:255 o usa 'text' si hiciste el cambio en la migración
                'ubicacion' => 'nullable|string', 
                
                'precio_jornada' => 'nullable|numeric|min:0',
                'precio_modulo' => 'nullable|numeric|min:0',

                'formulario_base_id' => 'required|exists:formularios_base,id',

                'conferencistas' => 'required|array',
                'conferencistas.*' => 'exists:perfil_conferencistas,id',

                'contenido_tematico' => 'required|array|min:1',
                'contenido_tematico.*.tema' => 'required|string',
                'contenido_tematico.*.subtemas' => 'required|array|min:1',
                'contenido_tematico.*.subtemas.*' => 'required|string',

                'color_hex_secundario' => 'nullable|string|max:7',
                'texto_dinamico' => 'nullable|string',
                'imagen_relacionada' => 'nullable|file|image|max:5120',
                'url_folleto' => 'nullable|file|mimes:pdf|max:5120',
            ]);

            $rutaImagen = null;
            $rutaFolleto = null;

            if ($request->hasFile('imagen_relacionada')) {
                $rutaImagen = $request->file('imagen_relacionada')->store('eventos/imagenes', 'public');
            }
            if ($request->hasFile('url_folleto')) {
                $rutaFolleto = $request->file('url_folleto')->store('eventos/folletos', 'public');
            }

            DB::transaction(function () use ($validated, $rutaImagen, $rutaFolleto) {


                $contenido = ContenidoTematico::create([
                    'modulos' => $validated['contenido_tematico'],
                    'alcance' => $validated['alcance'] ?? null,
                ]);

                $evento = Evento::create([
                    'organizador_id' => Auth::id(),
                    'contenido_tematico_id' => $contenido->id,
                    'formulario_base_id' => $validated['formulario_base_id'],
                    'area_formacion_id' => $validated['area_formacion_id'],
                    'estado_id' => $validated['estado_id'],
                    'titulo' => $validated['titulo'],
                    'subtitulo' => $validated['subtitulo'] ?? null,
                    'modalidad' => $validated['modalidad'],
                    'fecha_hora' => $validated['fecha_hora'],
                    'ubicacion' => $validated['ubicacion'] ?? null,
                    'precio_jornada' => $validated['precio_jornada'] ?? null,
                    'precio_modulo' => $validated['precio_modulo'] ?? null,
                    'texto_dinamico' => $validated['texto_dinamico'] ?? null,
                    'color_hex_secundario' => $validated['color_hex_secundario'] ?? null,
                    'imagen_relacionada' => $rutaImagen,
                    'url_folleto' => $rutaFolleto,
                ]);

                // 3. Guardar Relación de Conferencistas
                if (!empty($validated['conferencistas'])) {
                    $evento->conferencistas()->sync($validated['conferencistas']);
                }
            });

            return redirect()->back()->with('success', 'Evento corporativo creado exitosamente.');
            
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
       
            Log::error('Error crítico al crear Evento: ' . $e->getMessage());
            
            return back()->withErrors([
                'general' => 'Ocurrió un error en la base de datos (Ej: Un texto es demasiado largo para el campo). Revise la longitud de su ubicación o descripción.'
            ]);
        }
    }
}